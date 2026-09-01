<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RobinsonRyan\Dibs\Actions\UnassignBookingHost;
use RobinsonRyan\Dibs\Events\BookingHostUnassigned;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('clears the role’s host and reports who it was (R44)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();
    $alice = user('Alice');

    BookingHost::factory()->for($booking)->host($alice, 'interviewer')->create();

    Event::fake([BookingHostUnassigned::class]);

    $returned = (new UnassignBookingHost)($booking, 'interviewer');

    expect(BookingHost::query()->where('booking_id', $booking->id)->count())->toBe(0)
        ->and($returned->relationLoaded('hosts'))->toBeTrue()
        ->and($returned->hosts)->toHaveCount(0);

    Event::assertDispatched(
        BookingHostUnassigned::class,
        fn (BookingHostUnassigned $event): bool => $event->booking->is($booking)
            && $event->role === 'interviewer'
            && $event->previousHost->is($alice),
    );
});

it('does nothing and announces nothing when the role has no host (R44)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();

    Event::fake([BookingHostUnassigned::class]);

    (new UnassignBookingHost)($booking, 'interviewer');

    Event::assertNotDispatched(BookingHostUnassigned::class);

    expect(BookingHost::query()->count())->toBe(0);
});

it('leaves the other roles assigned (R44)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();
    $room = room('Bishop’s office');

    BookingHost::factory()->for($booking)->host(user('Alice'), 'interviewer')->create();
    BookingHost::factory()->for($booking)->host($room, 'room')->create();

    (new UnassignBookingHost)($booking, 'interviewer');

    $rows = BookingHost::query()->where('booking_id', $booking->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->role)->toBe('room')
        ->and($rows->first()->host_id)->toBe((string) $room->getKey());
});

it('refuses to change a cancelled booking’s assignment (R44, B34)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->cancelled()->create();

    BookingHost::factory()->for($booking)->host(user('Alice'), 'interviewer')->create();

    Event::fake([BookingHostUnassigned::class]);

    expect(fn (): Booking => (new UnassignBookingHost)($booking, 'interviewer'))
        ->toThrow(InvalidTransition::class);

    expect(BookingHost::query()->where('booking_id', $booking->id)->count())->toBe(1);

    Event::assertNotDispatched(BookingHostUnassigned::class);
});

it('lets a completed booking’s record be corrected (R44, B34)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->completed()->create();

    BookingHost::factory()->for($booking)->host(user('Alice'), 'interviewer')->create();

    (new UnassignBookingHost)($booking, 'interviewer');

    expect(BookingHost::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('removes an assignment whose host record is gone, without an event (B35)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();

    BookingHost::query()->create([
        'booking_id' => $booking->id,
        'host_type' => 'user',
        'host_id' => (string) Str::orderedUuid(),
        'role' => 'interviewer',
    ]);

    Event::fake([BookingHostUnassigned::class]);

    (new UnassignBookingHost)($booking, 'interviewer');

    expect(BookingHost::query()->where('booking_id', $booking->id)->count())->toBe(0);

    Event::assertNotDispatched(BookingHostUnassigned::class);
});

it('defaults to the `host` role (R44)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();

    BookingHost::factory()->for($booking)->host(user('Alice'))->create();
    BookingHost::factory()->for($booking)->host(user('Bob'), 'driver')->create();

    (new UnassignBookingHost)($booking);

    expect(BookingHost::query()->where('booking_id', $booking->id)->pluck('role')->all())->toBe(['driver']);
});
