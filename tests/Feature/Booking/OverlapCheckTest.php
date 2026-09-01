<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\OverlapCheck;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function overlapFixture(CarbonImmutable $startsAt, int $minutes, Model $host, string $role = 'interviewer'): Booking
{
    $slot = Slot::factory()->adhoc()->at($startsAt, $minutes)->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($booking)->host($host, $role)->create();

    return $booking;
}

it('returns a host’s overlapping active bookings (R19)', function (): void {
    $alice = user('Alice');
    $window = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $inside = overlapFixture($window->addMinutes(10), 20, $alice);

    expect(OverlapCheck::for($alice, $window, $window->addHour())->pluck('id')->all())
        ->toBe([$inside->id]);
});

it('counts a booking that straddles either edge of the window', function (): void {
    $alice = user('Alice');
    $window = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $early = overlapFixture($window->subMinutes(30), 45, $alice);
    $late = overlapFixture($window->addMinutes(45), 45, $alice);

    expect(OverlapCheck::for($alice, $window, $window->addHour())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$early->id, $late->id])->sort()->values()->all());
});

it('does not count bookings that merely touch the window’s endpoints', function (): void {
    $alice = user('Alice');
    $window = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    overlapFixture($window->subMinutes(30), 30, $alice);
    overlapFixture($window->addHour(), 30, $alice);

    expect(OverlapCheck::for($alice, $window, $window->addHour()))->toBeEmpty();
});

it('ignores cancelled, completed and no-show bookings', function (): void {
    $alice = user('Alice');
    $window = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    overlapFixture($window->addMinutes(5), 20, $alice)->update(['status' => 'cancelled']);
    overlapFixture($window->addMinutes(5), 20, $alice)->update(['status' => 'completed']);
    overlapFixture($window->addMinutes(5), 20, $alice)->update(['status' => 'no_show']);

    expect(OverlapCheck::for($alice, $window, $window->addHour()))->toBeEmpty();
});

it('ignores another host’s bookings and looks across every role', function (): void {
    $alice = user('Alice');
    $window = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    overlapFixture($window->addMinutes(5), 20, user('Bob'));
    $asDriver = overlapFixture($window->addMinutes(5), 20, $alice, 'driver');

    expect(OverlapCheck::for($alice, $window, $window->addHour())->pluck('id')->all())
        ->toBe([$asDriver->id]);
});
