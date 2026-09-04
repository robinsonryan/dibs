<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\DeleteUnavailability;
use RobinsonRyan\Dibs\Actions\UpdateUnavailability;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\HostAvailability;
use RobinsonRyan\Dibs\Support\OverlapCheck;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function utc(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'UTC');
}

/**
 * A published day, optionally owned by a context and pooled on some people.
 *
 * @param  list<Model>  $hosts
 */
function awayDay(?Model $context = null, array $hosts = []): Availability
{
    $availability = Availability::factory()
        ->published()
        ->when($context instanceof Model, fn ($factory) => $factory->forContext($context))
        ->create();

    foreach ($hosts as $host) {
        AvailabilityHost::factory()->for($availability)->host($host, 'interviewer')->create();
    }

    return $availability;
}

/**
 * One time on that day — numbered by default, pool-derived when `$capacity` is
 * null (D18).
 */
function awayTime(Availability $availability, CarbonImmutable $startsAt, ?int $capacity = 1, int $minutes = 30): Slot
{
    return Slot::factory()
        ->for($availability)
        ->at($startsAt, $minutes)
        ->state(['capacity' => $capacity])
        ->create();
}

/**
 * @return array<int, string>
 */
function awayBookableIds(bool $requireFreeHost = true): array
{
    return Slot::bookable(null, $requireFreeHost)->orderBy('starts_at')->pluck('id')->all();
}

it('takes a host out of a time a one-off away overlaps, and leaves the next one alone', function (): void {
    $alice = user('Alice');
    $day = awayDay(hosts: [$alice]);
    $covered = awayTime($day, utc('2026-03-08 09:00:00'));
    $after = awayTime($day, utc('2026-03-08 10:00:00'));

    markAway(onceSpec($alice, '2026-03-08 08:45:00', '2026-03-08 09:15:00'));

    expect(awayBookableIds())->toBe([$after->id])
        // Plain bookable() knows nothing about who is free, as it never did.
        ->and(awayBookableIds(requireFreeHost: false))->toBe([$covered->id, $after->id])
        ->and(HostAvailability::freeHosts($day, $covered, 'interviewer')->count())->toBe(0)
        ->and(HostAvailability::freeHosts($day, $after, 'interviewer')->count())->toBe(1);
});

it('says a host with an away is not free, though nothing is booked against them', function (): void {
    $alice = user('Alice');

    markAway(onceSpec($alice, '2026-03-08 08:45:00', '2026-03-08 09:15:00'));

    expect(HostAvailability::isFree($alice, utc('2026-03-08 09:00:00'), utc('2026-03-08 09:30:00')))->toBeFalse()
        // An away is not a claim: the list of bookings is still a list of bookings.
        ->and(HostAvailability::busyBookings($alice, utc('2026-03-08 09:00:00'), utc('2026-03-08 09:30:00')))->toHaveCount(0)
        ->and(OverlapCheck::isAway($alice, utc('2026-03-08 09:00:00'), utc('2026-03-08 09:30:00')))->toBeTrue()
        ->and(HostAvailability::isFree($alice, utc('2026-03-08 09:15:00'), utc('2026-03-08 09:45:00')))->toBeTrue();
});

it('holds a standing away at the same hour on the scope’s own clock across a daylight-saving change', function (): void {
    $alice = user('Alice');
    $day = awayDay(hosts: [$alice]);

    // 6:30 pm in Denver on two consecutive Sundays — the clocks move between
    // them, so the two are an hour apart in UTC.
    $beforeTheChange = awayTime($day, utc('2026-03-02 01:30:00'));
    $afterTheChange = awayTime($day, utc('2026-03-09 00:30:00'));
    // 7:30 pm on the second Sunday: outside the away either way.
    $later = awayTime($day, utc('2026-03-09 01:30:00'));

    markAway(weeklySpec($alice, [new WindowSpec(0, 18 * 60, 19 * 60)]));

    expect(awayBookableIds())->toBe([$later->id])
        ->and(awayBookableIds())->not->toContain($beforeTheChange->id)
        ->and(awayBookableIds())->not->toContain($afterTheChange->id);
});

it('hides every time in a context for the span of that context’s away, and only that span', function (): void {
    $ward = organization('First Ward');
    $otherWard = organization('Second Ward');
    $alice = user('Alice');

    $day = awayDay($ward, [$alice]);
    $covered = awayTime($day, utc('2026-03-08 18:30:00'));
    $outside = awayTime($day, utc('2026-03-08 22:00:00'));
    $elsewhere = awayTime(awayDay($otherWard, [$alice]), utc('2026-03-08 18:30:00'));

    markAway(onceSpec($ward, '2026-03-08 18:00:00', '2026-03-08 21:00:00', label: 'Youth conference'));

    expect(awayBookableIds())->toBe([$elsewhere->id, $outside->id])
        // A ward-wide away means nothing is offered, so it bites even when the
        // caller is not asking about who is free.
        ->and(awayBookableIds(requireFreeHost: false))->toBe([$elsewhere->id, $outside->id])
        ->and(awayBookableIds(requireFreeHost: false))->not->toContain($covered->id);
});

it('leaves a context’s times alone once its away is removed', function (): void {
    $ward = organization('First Ward');
    $day = awayDay($ward, [user('Alice')]);
    $time = awayTime($day, utc('2026-03-08 18:30:00'));

    $away = markAway(onceSpec($ward, '2026-03-08 18:00:00', '2026-03-08 21:00:00'));

    expect(awayBookableIds())->toBe([]);

    (new DeleteUnavailability)($away);

    expect(awayBookableIds())->toBe([$time->id]);
});

it('drops the holder who is away from what a time can hold', function (): void {
    $alice = user('Alice');
    $bob = user('Bob');
    $carol = user('Carol');

    $day = awayDay(hosts: [$alice, $bob, $carol]);
    $time = awayTime($day, utc('2026-03-08 09:00:00'), capacity: null);

    expect($time->capacityFor())->toBe(3);

    $away = markAway(onceSpec($bob, '2026-03-08 08:45:00', '2026-03-08 09:15:00'));

    expect($time->fresh()?->capacityFor())->toBe(2)
        ->and(HostAvailability::freeHolders($day, $time)->pluck('id')->all())
        ->toBe([(string) $alice->getKey(), (string) $carol->getKey()]);

    (new DeleteUnavailability)($away);

    expect($time->fresh()?->capacityFor())->toBe(3);
});

it('takes a time down to nothing when its whole context is away', function (): void {
    $ward = organization('First Ward');
    $alice = user('Alice');
    $day = awayDay($ward, [$alice]);
    $time = awayTime($day, utc('2026-03-08 09:00:00'), capacity: null);

    markAway(onceSpec($ward, '2026-03-08 08:00:00', '2026-03-08 10:00:00'));

    expect($time->fresh()?->capacityFor())->toBe(0);
});

it('gives back the times an away no longer covers when it is narrowed, and takes more when it is widened', function (): void {
    $alice = user('Alice');
    $day = awayDay(hosts: [$alice]);
    $morning = awayTime($day, utc('2026-03-08 09:00:00'));
    $noon = awayTime($day, utc('2026-03-08 12:00:00'));
    $evening = awayTime($day, utc('2026-03-08 18:00:00'));

    $away = markAway(onceSpec($alice, '2026-03-08 08:00:00', '2026-03-08 13:00:00'));

    expect(awayBookableIds())->toBe([$evening->id]);

    $narrowed = (new UpdateUnavailability)($away, onceSpec($alice, '2026-03-08 08:00:00', '2026-03-08 10:00:00'));

    expect(awayBookableIds())->toBe([$noon->id, $evening->id]);

    (new UpdateUnavailability)($narrowed, onceSpec($alice, '2026-03-08 08:00:00', '2026-03-08 19:00:00'));

    expect(awayBookableIds())->toBe([])
        ->and(awayBookableIds(requireFreeHost: false))->toContain($morning->id);
});

it('leaves the exclusive-hosts reading of a time exactly as it was', function (): void {
    config()->set('dibs.exclusive_hosts', true);

    $alice = user('Alice');
    $bob = user('Bob');
    $day = awayDay(hosts: [$alice, $bob]);
    $time = awayTime($day, utc('2026-03-08 09:00:00'), capacity: null);

    // Alice's own claim on this time takes her out of it (D18); Bob is left.
    $booking = Booking::factory()->for($time, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host($alice, 'interviewer')->create();

    expect($time->fresh()?->capacityFor())->toBe(1)
        ->and(awayBookableIds())->toBe([$time->id]);

    // An away on the one holder left is the other half of the same subtraction.
    markAway(onceSpec($bob, '2026-03-08 08:45:00', '2026-03-08 09:15:00'));

    expect($time->fresh()?->capacityFor())->toBe(0)
        ->and(awayBookableIds())->toBe([]);
});

it('answers in a fixed number of queries however many days carry aways', function (): void {
    $ward = organization('First Ward');

    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Slot::bookable(null, true)->pluck('id')->all();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    $open = function (int $index) use ($ward): void {
        $host = user('Host '.$index);
        $day = awayDay($ward, [$host]);
        $at = utc('2026-03-08 09:00:00')->addDays($index);

        awayTime($day, $at, capacity: null);
        awayTime($day, $at->addHour(), capacity: null);

        // Every host is away over the first of their two times, and the ward
        // itself is away over an hour that touches neither.
        markAway(onceSpec($host, $at->format('Y-m-d H:i:s'), $at->addMinutes(30)->format('Y-m-d H:i:s')));
    };

    markAway(weeklySpec($ward, [new WindowSpec(3, 3 * 60, 4 * 60)]));

    foreach (range(0, 2) as $index) {
        $open($index);
    }

    $small = $measure();

    foreach (range(3, 11) as $index) {
        $open($index);
    }

    // Nine more days, nine more aways, nine more pools: not one more query.
    // The wall-clock rules are turned into instants once per read and handed to
    // the filter as values, so what grows is the size of one statement — never
    // the number of them.
    expect($measure())->toBe($small)
        ->and($small)->toBeLessThanOrEqual(8);
});

it('offers the second time of every day, and hides the first, at that same cost', function (): void {
    $ward = organization('First Ward');
    $kept = [];

    foreach (range(0, 3) as $index) {
        $host = user('Host '.$index);
        $day = awayDay($ward, [$host]);
        $at = utc('2026-03-08 09:00:00')->addDays($index);

        awayTime($day, $at, capacity: null);
        $kept[] = awayTime($day, $at->addHour(), capacity: null)->id;

        markAway(onceSpec($host, $at->format('Y-m-d H:i:s'), $at->addMinutes(30)->format('Y-m-d H:i:s')));
    }

    expect(awayBookableIds())->toBe($kept);
});
