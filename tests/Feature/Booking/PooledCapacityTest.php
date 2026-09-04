<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Dibs\Actions\AssignBookingHost;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Actions\MaterialiseSeries;
use RobinsonRyan\Dibs\Contracts\HostResolver;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\HostOverlap;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Room;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A calling stands for whoever holds it: a Room resolves to the users seated
 * in it, everybody else to themselves.
 *
 * @param  array<string, list<User>>  $seats
 */
function bindCallingResolver(array $seats): void
{
    app()->bind(HostResolver::class, fn (): HostResolver => new class($seats) implements HostResolver
    {
        /**
         * @param  array<string, list<User>>  $seats
         */
        public function __construct(private readonly array $seats) {}

        public function resolve(Model $host, CarbonInterface $at, ?Model $context = null): Collection
        {
            if (! $host instanceof Room) {
                return new Collection([$host]);
            }

            return new Collection($this->seats[(string) $host->getKey()] ?? []);
        }
    });
}

/**
 * A future time on a published availability with this pool, whose capacity is
 * derived from the pool: the `capacity` column is null.
 */
function poolDerivedSlotOn(Model ...$pool): Slot
{
    return Slot::factory()->for(pooledAvailability(...$pool))->fromPool()->create();
}

/**
 * A future one-appointment time whose pool is a **candidate list**: several
 * people may take it, but the numbered `capacity` column says the time itself
 * seats one.
 */
function candidateListSlotOn(Model ...$pool): Slot
{
    return Slot::factory()->for(pooledAvailability(...$pool))->capacity(1)->create();
}

function pooledAvailability(Model ...$pool): Availability
{
    $availability = Availability::factory()->published()->create();

    foreach ($pool as $member) {
        AvailabilityHost::factory()->for($availability)->host($member, 'interviewer')->create();
    }

    return $availability;
}

/**
 * A claim on the slot with this host on it, written straight to the rows —
 * no status bookkeeping, so the test says what the slot's state is.
 */
function claimedBy(Slot $slot, Model $host): Booking
{
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();

    BookingHost::factory()->for($booking)->host($host, 'interviewer')->create();

    return $booking;
}

it('takes one appointment per free person the pool resolves to when the capacity column is null', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([(string) $counselors->getKey() => [user('Rob'), user('Dan'), user('Sam')]]);

    $slot = poolDerivedSlotOn($counselors);

    expect($slot->capacity)->toBeNull();

    $ann = user('Ann');
    $bea = user('Bea');
    $cal = user('Cal');
    $dee = user('Dee');

    (new BookSlot)($slot->fresh(), $ann, $ann);
    (new BookSlot)($slot->fresh(), $bea, $bea);

    // Two claims in, a third person is still free: the slot is open and a
    // member is still offered it.
    expect($slot->fresh()->status)->toBe(SlotStatus::Open)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);

    (new BookSlot)($slot->fresh(), $cal, $cal);

    expect(Booking::active()->count())->toBe(3)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([])
        ->and(fn (): Booking => (new BookSlot)($slot->fresh(), $dee, $dee))
        ->toThrow(SlotUnavailable::class);
});

it('refuses the first appointment when the pool resolves to nobody', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([]);

    $slot = poolDerivedSlotOn($counselors);
    $ann = user('Ann');

    expect(fn (): Booking => (new BookSlot)($slot, $ann, $ann))->toThrow(SlotUnavailable::class)
        ->and(Booking::query()->count())->toBe(0)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Open);
});

it('drops a pooled slot to nobody free when its only holder is booked elsewhere', function (): void {
    $alice = user('Alice');
    $slot = poolDerivedSlotOn($alice);

    $elsewhere = Slot::factory()->adhoc()->at($slot->starts_at)->create();
    claimedBy($elsewhere, $alice);

    $ann = user('Ann');

    expect(fn (): Booking => (new BookSlot)($slot, $ann, $ann))->toThrow(SlotUnavailable::class);
});

it('still gates a slot with no pool on its capacity column', function (): void {
    $slot = Slot::factory()->capacity(2)->create();
    $ann = user('Ann');
    $bea = user('Bea');
    $cal = user('Cal');

    (new BookSlot)($slot->fresh(), $ann, $ann);
    (new BookSlot)($slot->fresh(), $bea, $bea);

    expect(Booking::active()->count())->toBe(2)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked)
        ->and(fn (): Booking => (new BookSlot)($slot->fresh(), $cal, $cal))
        ->toThrow(SlotUnavailable::class);
});

it('leaves a host claimed on this very slot free for it by default', function (): void {
    $rob = user('Rob');
    $dan = user('Dan');
    $slot = poolDerivedSlotOn($rob, $dan);

    claimedBy($slot, $rob);

    expect(config('dibs.exclusive_hosts'))->toBeFalse()
        ->and($slot->capacityFor())->toBe(2)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);
});

it('counts a host claimed on this very slot as busy for it when hosts are exclusive', function (): void {
    config(['dibs.exclusive_hosts' => true]);

    $rob = user('Rob');
    $dan = user('Dan');
    $slot = poolDerivedSlotOn($rob, $dan);

    claimedBy($slot, $rob);

    // One of the two is spoken for, so capacity drops by one — and the slot is
    // still offered, because the other is free.
    expect($slot->capacityFor())->toBe(1)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);
});

it('leaves no capacity and no bookable slot when the exclusive host was the only holder', function (): void {
    config(['dibs.exclusive_hosts' => true]);

    $rob = user('Rob');
    $slot = poolDerivedSlotOn($rob);

    claimedBy($slot, $rob);

    expect($slot->capacityFor())->toBe(0)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([]);
});

it('guards an assignment against a host already taken on the same slot when hosts are exclusive', function (): void {
    $rob = user('Rob');
    $dan = user('Dan');
    $slot = poolDerivedSlotOn($rob, $dan);

    claimedBy($slot, $rob);

    $second = Booking::factory()->for($slot, 'slot')->bookedFor(user('Bea'))->create();

    // By default the slot's own claims are not conflicts: one host may seat
    // two attendees in one session (R19).
    expect((new AssignBookingHost)($second, $rob, 'interviewer', true)->hosts()->count())->toBe(1);

    config(['dibs.exclusive_hosts' => true]);

    $third = Booking::factory()->for($slot, 'slot')->bookedFor(user('Cal'))->create();

    expect(fn (): Booking => (new AssignBookingHost)($third, $rob, 'interviewer', true))
        ->toThrow(HostOverlap::class);
});

it('opens a full pooled slot again when one of its appointments is cancelled', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([(string) $counselors->getKey() => [user('Rob'), user('Dan'), user('Sam')]]);

    $slot = poolDerivedSlotOn($counselors);

    $ann = user('Ann');
    $bea = user('Bea');
    $cal = user('Cal');

    $first = (new BookSlot)($slot->fresh(), $ann, $ann);
    (new BookSlot)($slot->fresh(), $bea, $bea);
    (new BookSlot)($slot->fresh(), $cal, $cal);

    expect($slot->fresh()->status)->toBe(SlotStatus::Booked);

    (new CancelBooking)($first);

    // Two claims against three free holders: the seat that was given back is
    // on offer again. Read against the `capacity` column (1) it would have
    // stayed `booked` and the third counselor's hour would have been lost.
    expect($slot->fresh()->status)->toBe(SlotStatus::Open)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);
});

it('takes one appointment per free holder on a series-made time, and refuses the fourth', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([(string) $counselors->getKey() => [user('Rob'), user('Dan'), user('Sam')]]);

    $series = openSeries([new WindowSpec(0, 18 * 60, 18 * 60 + 30)], host: $counselors);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $slot = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail()
        ->slots()->orderBy('starts_at')->firstOrFail();

    // A time laid down by a rule is measured by its pool, not by a number.
    expect($slot->capacity)->toBeNull();

    $ann = user('Ann');
    $bea = user('Bea');
    $cal = user('Cal');
    $dee = user('Dee');

    (new BookSlot)($slot->fresh(), $ann, $ann);
    (new BookSlot)($slot->fresh(), $bea, $bea);
    (new BookSlot)($slot->fresh(), $cal, $cal);

    expect(Booking::active()->count())->toBe(3)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked)
        ->and(fn (): Booking => (new BookSlot)($slot->fresh(), $dee, $dee))
        ->toThrow(SlotUnavailable::class);
});

it('takes exactly one appointment on a candidate-list time, however many of its pool are free', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([(string) $counselors->getKey() => [user('Rob'), user('Dan'), user('Sam')]]);

    $slot = candidateListSlotOn($counselors);

    $ann = user('Ann');
    $bea = user('Bea');

    (new BookSlot)($slot->fresh(), $ann, $ann);

    // Three people could have taken it; the time itself still seats one.
    expect(Booking::active()->count())->toBe(1)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([])
        ->and(fn (): Booking => (new BookSlot)($slot->fresh(), $bea, $bea))
        ->toThrow(SlotUnavailable::class);
});

it('books a candidate-list time whose whole pool is busy, and takes the acknowledged double assignment', function (): void {
    $alice = user('Alice');
    $bob = user('Bob');
    $slot = candidateListSlotOn($alice, $bob);

    // Both candidates are spoken for across this time, elsewhere.
    claimedBy(Slot::factory()->adhoc()->at($slot->starts_at)->create(), $alice);
    claimedBy(Slot::factory()->adhoc()->at($slot->starts_at)->create(), $bob);

    $member = user('Member');

    // The time is one appointment a leader may book: the pool is who could take
    // it, not how many it seats, so nobody being free does not close it.
    $booking = (new BookSlot)($slot->fresh(), $member, $member);

    // Giving it to Alice anyway is the consumer's call: unguarded it is taken,
    // guarded it is refused.
    expect((new AssignBookingHost)($booking, $alice, 'interviewer')->hosts()->count())->toBe(1)
        ->and(fn (): Booking => (new AssignBookingHost)($booking->fresh(), $bob, 'interviewer', true))
        ->toThrow(HostOverlap::class);
});

it('reports a numbered capacity from the column and a null one from the pool', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([(string) $counselors->getKey() => [user('Rob'), user('Dan'), user('Sam')]]);

    $availability = pooledAvailability($counselors);
    $numbered = Slot::factory()->for($availability)->capacity(2)->create();
    $derived = Slot::factory()->for($availability)->fromPool()->create();

    expect($numbered->capacityFor())->toBe(2)
        ->and($derived->capacityFor())->toBe(3);
});

it('offers a numbered-capacity time only while somebody in its pool is free', function (): void {
    $rob = user('Rob');
    $dan = user('Dan');

    $availability = pooledAvailability($rob, $dan);
    $slot = Slot::factory()->for($availability)->capacity(2)->create();

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);

    // Rob is spoken for elsewhere; Dan could still take it.
    claimedBy(Slot::factory()->adhoc()->at($slot->starts_at)->create(), $rob);

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);

    claimedBy(Slot::factory()->adhoc()->at($slot->starts_at)->create(), $dan);

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([])
        ->and($slot->capacityFor())->toBe(2);
});

it('leaves a numbered-capacity time on offer when its own claim is all that is against its host', function (): void {
    config(['dibs.exclusive_hosts' => true]);

    $rob = user('Rob');
    $slot = Slot::factory()->for(pooledAvailability($rob))->capacity(2)->create();

    claimedBy($slot, $rob);

    // Exclusive hosts take a holder out of a *pool-derived* time they are
    // already claimed on. Here the number is the cap, so the time's own claim
    // says nothing about who is free for it: Rob is still its host, and the
    // second appointment is still on offer.
    expect($slot->capacityFor())->toBe(2)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);
});
