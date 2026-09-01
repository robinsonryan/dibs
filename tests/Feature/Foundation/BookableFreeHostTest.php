<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<int, string>
 */
function freeHostBookableIds(): array
{
    return Slot::bookable(null, true)->pluck('id')->all();
}

/**
 * @return array<int, string>
 */
function plainBookableIds(): array
{
    return Slot::bookable()->pluck('id')->all();
}

function pooledSlot(CarbonImmutable $startsAt, Model ...$hosts): Slot
{
    $availability = Availability::factory()->published()->create();

    foreach ($hosts as $host) {
        AvailabilityHost::factory()->for($availability)->host($host, 'interviewer')->create();
    }

    return Slot::factory()->for($availability)->at($startsAt, 30)->create();
}

function busyElsewhere(Model $host, CarbonImmutable $startsAt, int $minutes = 30): Booking
{
    $slot = Slot::factory()->adhoc()->at($startsAt, $minutes)->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host($host, 'interviewer')->create();

    return $booking;
}

it('excludes a slot whose only pool member is busy, and leaves plain bookable() alone (R47)', function (): void {
    $alice = user('Alice');
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = pooledSlot($at, $alice);
    busyElsewhere($alice, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([])
        ->and(plainBookableIds())->toContain($slot->id);
});

it('brings the slot back once the busy booking is cancelled (R47)', function (): void {
    $alice = user('Alice');
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = pooledSlot($at, $alice);
    $booking = busyElsewhere($alice, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([]);

    (new CancelBooking)($booking);

    expect(freeHostBookableIds())->toContain($slot->id);
});

it('keeps a slot whose pool of three has one busy member, and drops it when all three are (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');
    $bob = user('Bob');
    $carol = user('Carol');

    $slot = pooledSlot($at, $alice, $bob, $carol);
    busyElsewhere($bob, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toContain($slot->id);

    busyElsewhere($alice, $at->addMinutes(10), 20);
    busyElsewhere($carol, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([]);
});

it('never excludes an availability with no host pool (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = pooledSlot($at);

    expect(freeHostBookableIds())->toBe([$slot->id]);
});

it('reads each slot against its own availability’s pool (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');
    $bob = user('Bob');

    pooledSlot($at, $alice);
    $free = pooledSlot($at, $bob);
    busyElsewhere($alice, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([$free->id]);
});

it('treats the slot as half-open: a booking that ends when it starts leaves the host free (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');

    $slot = pooledSlot($at, $alice);
    busyElsewhere($alice, $at->subMinutes(30), 30);
    busyElsewhere($alice, $at->addMinutes(30), 30);

    expect(freeHostBookableIds())->toBe([$slot->id]);
});

it('does not count a pool member’s booking on the slot itself (D15)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');

    $availability = Availability::factory()->published()->create();
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    $slot = Slot::factory()->for($availability)->capacity(2)->at($at, 30)->create();

    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host($alice, 'interviewer')->create();

    expect(freeHostBookableIds())->toBe([$slot->id]);
});

it('answers in a single query however many slots there are (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    foreach (['Alice', 'Bob', 'Carol'] as $index => $name) {
        $host = user($name);
        pooledSlot($at->addHours($index), $host);
        busyElsewhere($host, $at->addHours($index)->addMinutes(10), 20);
    }

    pooledSlot($at->addDay(), user('Dave'));

    DB::flushQueryLog();
    DB::enableQueryLog();

    $ids = Slot::bookable(now: null, requireFreeHost: true)->pluck('id')->all();

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($ids)->toHaveCount(1);

    DB::disableQueryLog();
});
