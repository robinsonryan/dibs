<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\CreateSeries;
use RobinsonRyan\Dibs\Actions\MaterialiseSeries;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;

afterEach(function (): void {
    DB::setDefaultConnection('testing');

    foreach (['testing', 'testing_b'] as $name) {
        $connection = DB::connection($name);

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $connection->statement('set lock_timeout = 0');
        $connection->flushQueryLog();
        $connection->disableQueryLog();
    }

    CarbonImmutable::setTestNow();

    // The Feature suite shares this database in the same process; these tests
    // really commit, so nothing may survive them.
    DB::connection('testing')->statement(
        'TRUNCATE dibs_booking_hosts, dibs_bookings, dibs_offer_slots, dibs_offers, '.
        'dibs_availability_hosts, dibs_slots, dibs_availabilities, '.
        'dibs_series_hosts, dibs_series_windows, dibs_series, '.
        'fixture_users, fixture_rooms, fixture_organizations CASCADE',
    );
});

/**
 * Every statement the connection got an answer to. A statement cancelled by the
 * lock timeout never lands here, so an empty list means the action blocked on
 * its very first query.
 *
 * @return list<string>
 */
function seriesStatements(Connection $connection): array
{
    $queries = [];

    foreach ($connection->getQueryLog() as $entry) {
        $queries[] = is_string($entry['query'] ?? null) ? $entry['query'] : '';
    }

    return $queries;
}

it('waits for a rival materialisation instead of laying the same day down twice', function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');

    $bishop = User::create(['name' => 'Bishop']);

    $series = (new CreateSeries)(new SeriesSpec(
        title: 'Sunday evenings',
        context: $bishop,
        timezone: 'America/Denver',
        cadence: Cadence::Weekly,
        ordinals: [],
        startsOn: CarbonImmutable::parse('2026-03-01'),
        endsOn: null,
        slotDurationMinutes: 30,
        slotPaddingMinutes: 0,
        minNoticeMinutes: null,
        maxHorizonDays: null,
        location: null,
        windows: [new WindowSpec(0, 18 * 60, 20 * 60)],
        hosts: [new HostAssignment($bishop, 'interviewer')],
    ));

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A is the nightly sweep, holding the series row.
    $a->beginTransaction();
    $a->select('select id from dibs_series where id = ? for update', [$series->id]);
    $a->insert(
        'insert into dibs_availabilities (context_type, context_id, name, starts_at, ends_at, '.
        'slot_duration_minutes, status, series_id, occurs_on, window_index, rule_version, created_at, updated_at) '.
        'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
        ['user', (string) $bishop->getKey(), 'Sunday evenings', '2026-03-02 01:00:00+00', '2026-03-02 03:00:00+00',
            30, 'published', $series->id, '2026-03-01', 0, 1],
    );

    // Session B is a leader saving the same series in another worker.
    DB::setDefaultConnection('testing_b');
    $onB = Series::query()->whereKey($series->id)->firstOrFail();

    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new MaterialiseSeries)($onB, CarbonImmutable::parse('2026-03-01'));
        $this->fail('Expected session B to block on the series row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // It blocked on its very first statement: it read no dates and wrote no day.
    expect(seriesStatements($b))->toBe([]);

    $b->disableQueryLog();

    // A commits the day it made. B, still holding its stale copy, must see it
    // and create nothing — not collide with the occurrence key.
    DB::setDefaultConnection('testing');
    $a->commit();

    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect((new MaterialiseSeries)($onB, CarbonImmutable::parse('2026-03-01')))->toBe(0);

    DB::setDefaultConnection('testing');

    expect(Availability::query()->where('series_id', $series->id)->count())->toBe(1);
});
