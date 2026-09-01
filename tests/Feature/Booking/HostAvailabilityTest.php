<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\HostAvailability;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function availabilityWindow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
}

function elsewhereSlot(CarbonImmutable $startsAt, int $minutes = 30, ?Availability $availability = null): Slot
{
    $factory = $availability instanceof Availability
        ? Slot::factory()->for($availability)
        : Slot::factory()->adhoc();

    return $factory->at($startsAt, $minutes)->create();
}

function hostedBooking(Slot $slot, Model $host, string $role = 'host', ?string $type = null): Booking
{
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->type($type)->create();
    BookingHost::factory()->for($booking)->host($host, $role)->create();

    return $booking;
}

it('lists a host’s overlapping active bookings ordered by slot start (R45)', function (): void {
    $alice = user('Alice');
    $window = availabilityWindow();

    $late = hostedBooking(elsewhereSlot($window->addMinutes(40), 20), $alice);
    $early = hostedBooking(elsewhereSlot($window->addMinutes(5), 20), $alice);

    expect(HostAvailability::busyBookings($alice, $window, $window->addHour())->pluck('id')->all())
        ->toBe([$early->id, $late->id])
        ->and(HostAvailability::isFree($alice, $window, $window->addHour()))->toBeFalse();
});

it('counts a booking of any kind, on any availability, in any role (R45)', function (): void {
    $alice = user('Alice');
    $window = availabilityWindow();
    $elsewhere = Availability::factory()->published()->create();

    hostedBooking(
        elsewhereSlot($window->addMinutes(5), 20, $elsewhere),
        $alice,
        'interviewer',
        'tithing-settlement',
    );

    expect(HostAvailability::isFree($alice, $window, $window->addHour()))->toBeFalse();
});

it('leaves a host free when the overlapping booking is someone else’s (R45)', function (): void {
    $alice = user('Alice');
    $window = availabilityWindow();

    hostedBooking(elsewhereSlot($window->addMinutes(5), 20), user('Bob'));

    expect(HostAvailability::isFree($alice, $window, $window->addHour()))->toBeTrue()
        ->and(HostAvailability::busyBookings($alice, $window, $window->addHour()))->toBeEmpty();
});

it('treats the window as half-open: a booking that ends when it starts does not conflict (R45)', function (): void {
    $alice = user('Alice');
    $window = availabilityWindow();

    hostedBooking(elsewhereSlot($window->subMinutes(30), 30), $alice);
    hostedBooking(elsewhereSlot($window->addHour(), 30), $alice);

    expect(HostAvailability::busyBookings($alice, $window, $window->addHour()))->toBeEmpty()
        ->and(HostAvailability::isFree($alice, $window, $window->addHour()))->toBeTrue();
});

it('drops the excepted booking from the answer (R45)', function (): void {
    $alice = user('Alice');
    $window = availabilityWindow();

    $booking = hostedBooking(elsewhereSlot($window->addMinutes(5), 20), $alice);

    expect(HostAvailability::isFree($alice, $window, $window->addHour()))->toBeFalse()
        ->and(HostAvailability::isFree($alice, $window, $window->addHour(), $booking))->toBeTrue()
        ->and(HostAvailability::busyBookings($alice, $window, $window->addHour(), $booking))->toBeEmpty();
});

it('counts live claims only (R45)', function (): void {
    $alice = user('Alice');
    $window = availabilityWindow();

    hostedBooking(elsewhereSlot($window->addMinutes(5), 20), $alice)->update(['status' => 'cancelled']);
    hostedBooking(elsewhereSlot($window->addMinutes(5), 20), $alice)->update(['status' => 'completed']);
    hostedBooking(elsewhereSlot($window->addMinutes(5), 20), $alice)->update(['status' => 'no_show']);

    expect(HostAvailability::isFree($alice, $window, $window->addHour()))->toBeTrue();
});

it('returns the pool members free during a slot, as host models in pool order (R46)', function (): void {
    $alice = user('Alice');
    $bob = user('Bob');
    $carol = user('Carol');
    $window = availabilityWindow();

    $availability = Availability::factory()->published()->create();

    foreach ([$alice, $bob, $carol] as $host) {
        AvailabilityHost::factory()->for($availability)->host($host, 'interviewer')->create();
    }

    $slot = Slot::factory()->for($availability)->at($window, 30)->create();
    hostedBooking(elsewhereSlot($window->addMinutes(10), 20), $bob, 'interviewer');

    $free = HostAvailability::freeHosts($availability, $slot, 'interviewer');

    expect($free->pluck('id')->all())->toBe([$alice->id, $carol->id])
        ->and($free->first())->toBeInstanceOf(User::class);
});

it('returns nobody when every pool member is busy (R46)', function (): void {
    $window = availabilityWindow();
    $availability = Availability::factory()->published()->create();

    foreach (['Alice', 'Bob'] as $name) {
        $host = user($name);
        AvailabilityHost::factory()->for($availability)->host($host, 'interviewer')->create();
        hostedBooking(elsewhereSlot($window->addMinutes(10), 20), $host, 'interviewer');
    }

    $slot = Slot::factory()->for($availability)->at($window, 30)->create();

    expect(HostAvailability::freeHosts($availability, $slot, 'interviewer'))->toBeEmpty();
});

it('reads one role of the pool at a time (R46)', function (): void {
    $window = availabilityWindow();
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    $room = room('Room 1');

    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host($room, 'room')->create();

    $slot = Slot::factory()->for($availability)->at($window, 30)->create();

    expect(HostAvailability::freeHosts($availability, $slot, 'room')->pluck('id')->all())->toBe([$room->id])
        ->and(HostAvailability::freeHosts($availability, $slot))->toBeEmpty();
});

it('still counts a host free when their only booking is on this very slot (D15)', function (): void {
    $window = availabilityWindow();
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');

    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();

    $slot = Slot::factory()->for($availability)->capacity(2)->at($window, 30)->create();
    hostedBooking($slot, $alice, 'interviewer');

    expect(HostAvailability::freeHosts($availability, $slot, 'interviewer')->pluck('id')->all())
        ->toBe([$alice->id]);
});

it('has nobody free when the pool is empty (R46)', function (): void {
    $availability = Availability::factory()->published()->create();
    $slot = Slot::factory()->for($availability)->at(availabilityWindow(), 30)->create();

    expect(HostAvailability::freeHosts($availability, $slot, 'interviewer'))->toBeEmpty();
});
