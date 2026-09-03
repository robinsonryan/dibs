<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Dibs\Actions\AssignBookingHost;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Contracts\HostResolver;
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
 * A future capacity-1 slot on a published availability with this pool.
 */
function slotPooledOn(Model ...$pool): Slot
{
    $availability = Availability::factory()->published()->create();

    foreach ($pool as $member) {
        AvailabilityHost::factory()->for($availability)->host($member, 'interviewer')->create();
    }

    return Slot::factory()->for($availability)->create();
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

it('takes one appointment per free person the pool resolves to, whatever the capacity column says', function (): void {
    $counselors = room('Bishopric Counselor');
    bindCallingResolver([(string) $counselors->getKey() => [user('Rob'), user('Dan'), user('Sam')]]);

    $slot = slotPooledOn($counselors);

    expect($slot->capacity)->toBe(1);

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

    $slot = slotPooledOn($counselors);
    $ann = user('Ann');

    expect(fn (): Booking => (new BookSlot)($slot, $ann, $ann))->toThrow(SlotUnavailable::class)
        ->and(Booking::query()->count())->toBe(0)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Open);
});

it('drops a pooled slot to nobody free when its only holder is booked elsewhere', function (): void {
    $alice = user('Alice');
    $slot = slotPooledOn($alice);

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
    $slot = slotPooledOn($rob, $dan);

    claimedBy($slot, $rob);

    expect(config('dibs.exclusive_hosts'))->toBeFalse()
        ->and($slot->capacityFor())->toBe(2)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);
});

it('counts a host claimed on this very slot as busy for it when hosts are exclusive', function (): void {
    config(['dibs.exclusive_hosts' => true]);

    $rob = user('Rob');
    $dan = user('Dan');
    $slot = slotPooledOn($rob, $dan);

    claimedBy($slot, $rob);

    // One of the two is spoken for, so capacity drops by one — and the slot is
    // still offered, because the other is free.
    expect($slot->capacityFor())->toBe(1)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);
});

it('leaves no capacity and no bookable slot when the exclusive host was the only holder', function (): void {
    config(['dibs.exclusive_hosts' => true]);

    $rob = user('Rob');
    $slot = slotPooledOn($rob);

    claimedBy($slot, $rob);

    expect($slot->capacityFor())->toBe(0)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([]);
});

it('guards an assignment against a host already taken on the same slot when hosts are exclusive', function (): void {
    $rob = user('Rob');
    $dan = user('Dan');
    $slot = slotPooledOn($rob, $dan);

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
