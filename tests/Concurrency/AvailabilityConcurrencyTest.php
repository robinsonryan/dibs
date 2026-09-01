<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\DeleteAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;

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

    // The Feature suite shares this database in the same process; these tests
    // really commit, so nothing may survive them.
    DB::connection('testing')->statement(
        'TRUNCATE dibs_booking_hosts, dibs_bookings, dibs_offer_slots, dibs_offers, '.
        'dibs_availability_hosts, dibs_slots, dibs_availabilities, '.
        'fixture_users, fixture_rooms, fixture_organizations CASCADE',
    );
});

/**
 * Every statement the connection got an answer to. A statement cancelled by the
 * lock timeout never lands here, so an empty list means the action blocked on
 * its very first query — it read nothing from around the lock.
 *
 * @return list<string>
 */
function availabilityStatements(Connection $connection): array
{
    $queries = [];

    foreach ($connection->getQueryLog() as $entry) {
        $queries[] = is_string($entry['query'] ?? null) ? $entry['query'] : '';
    }

    return $queries;
}

it('waits for a rival transition instead of publishing over it, and writes nothing while it waits', function (): void {
    $availability = Availability::factory()
        ->draft()
        ->window(now()->addWeek()->startOfHour()->toImmutable(), now()->addWeek()->startOfHour()->addHours(2)->toImmutable())
        ->geometry(30)
        ->create();

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A is mid-transition on the very row B is about to read.
    $a->beginTransaction();
    $a->select('select id from dibs_availabilities where id = ? for update', [$availability->id]);

    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new PublishAvailability)($availability);
        $this->fail('Expected session B to block on the availability row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // It blocked on its very first statement: no status write, no slot grid.
    expect(availabilityStatements($b))->toBe([]);

    $b->disableQueryLog();

    // A publishes it after all; B's stale draft copy must not publish it twice.
    DB::setDefaultConnection('testing');
    $a->update('update dibs_availabilities set status = ? where id = ?', ['published', $availability->id]);
    $a->commit();

    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn (): Availability => (new PublishAvailability)($availability))
        ->toThrow(InvalidTransition::class);

    DB::setDefaultConnection('testing');

    expect($availability->fresh()?->status)->toBe(AvailabilityStatus::Published)
        ->and(Slot::query()->count())->toBe(0);
});

it('waits for a rival hold before deciding a deletion, and refuses once it sees it', function (): void {
    $availability = Availability::factory()
        ->draft()
        ->window(now()->addWeek()->startOfHour()->toImmutable(), now()->addWeek()->startOfHour()->addHour()->toImmutable())
        ->geometry(30)
        ->create();

    (new PublishAvailability)($availability);
    $slot = Slot::query()->where('availability_id', $availability->id)->orderBy('starts_at')->firstOrFail();

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A is taking a hold on one of the slots — uncommitted.
    $a->beginTransaction();
    $a->select('select id from dibs_slots where id = ? for update', [$slot->id]);

    // Session B is a second process: it reads the availability on its own
    // connection, as a request handler in another worker would.
    DB::setDefaultConnection('testing_b');
    $onB = Availability::query()->whereKey($availability->id)->firstOrFail();

    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new DeleteAvailability)($onB);
        $this->fail('Expected session B to block on the slot row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    expect(availabilityStatements($b))->toBe([]);

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    $a->update('update dibs_slots set status = ? where id = ?', ['held', $slot->id]);
    $a->commit();

    // The hold is real now, so the delete is refused rather than cascading it away.
    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn () => (new DeleteAvailability)($onB))->toThrow(DeletionRefused::class);

    DB::setDefaultConnection('testing');

    expect(Availability::query()->count())->toBe(1)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Held);
});
