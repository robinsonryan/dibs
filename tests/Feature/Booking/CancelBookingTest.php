<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('cancels a live booking and stamps who cancelled it (R22)', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $clerk = user('Clerk');
    $booking = (new BookSlot)($slot, $alice, $alice);

    $cancelled = (new CancelBooking)($booking, $clerk);

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancelled_at?->equalTo(CarbonImmutable::now('UTC')))->toBeTrue()
        ->and($cancelled->cancelled_by_type)->toBe('user')
        ->and($cancelled->cancelled_by_id)->toBe((string) $clerk->getKey());
});

it('cancels without a canceller', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $booking = (new BookSlot)($slot, $alice, $alice);

    $cancelled = (new CancelBooking)($booking);

    expect($cancelled->cancelled_by_type)->toBeNull()
        ->and($cancelled->cancelled_by_id)->toBeNull()
        ->and($cancelled->cancelled_at)->not->toBeNull();
});

it('returns a future availability-born slot to the bookable pool (D3)', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $booking = (new BookSlot)($slot, $alice, $alice);

    expect(Slot::bookable()->pluck('id')->all())->toBe([]);

    (new CancelBooking)($booking);

    expect($slot->fresh()->status)->toBe(SlotStatus::Open)
        ->and(Slot::bookable()->pluck('id')->all())->toBe([$slot->id]);
});

it('reopens a full capacity-2 slot when one of its bookings is cancelled', function (): void {
    $slot = Slot::factory()->capacity(2)->create();
    $alice = user('Alice');
    $bob = user('Bob');

    $first = (new BookSlot)($slot->fresh(), $alice, $alice);
    (new BookSlot)($slot->fresh(), $bob, $bob);
    expect($slot->fresh()->status)->toBe(SlotStatus::Booked);

    (new CancelBooking)($first);

    expect($slot->fresh()->status)->toBe(SlotStatus::Open)
        ->and($slot->fresh()->activeBookings()->count())->toBe(1);
});

it('keeps an adhoc slot alive but unbookable after cancellation (D3)', function (): void {
    $slot = Slot::factory()->adhoc()->create();
    $alice = user('Alice');
    $booking = (new BookSlot)($slot, $alice, $alice);

    (new CancelBooking)($booking);

    expect($slot->fresh())->not->toBeNull()
        ->and($slot->fresh()->status)->toBe(SlotStatus::Open)
        ->and(Slot::bookable()->pluck('id')->all())->toBe([]);
});

it('refuses to cancel a booking twice', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $booking = (new BookSlot)($slot, $alice, $alice);

    (new CancelBooking)($booking);

    expect(fn (): Booking => (new CancelBooking)($booking))->toThrow(InvalidTransition::class);
});

it('refuses to cancel a completed or no-show booking', function (): void {
    $completed = Booking::factory()->completed()->create();
    $noShow = Booking::factory()->noShow()->create();

    expect(fn (): Booking => (new CancelBooking)($completed))->toThrow(InvalidTransition::class)
        ->and(fn (): Booking => (new CancelBooking)($noShow))->toThrow(InvalidTransition::class);
});

it('refuses to cancel from a stale in-memory copy of a cancelled booking', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $booking = (new BookSlot)($slot, $alice, $alice);
    $stale = $booking->fresh();

    (new CancelBooking)($booking);

    expect(fn (): Booking => (new CancelBooking)($stale))->toThrow(InvalidTransition::class);
});
