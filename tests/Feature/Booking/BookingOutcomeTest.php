<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\CompleteBooking;
use RobinsonRyan\Dibs\Actions\MarkNoShow;
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

it('completes a live booking (R23)', function (): void {
    $booking = Booking::factory()->booked()->bookedFor(user('Alice'))->create();

    expect((new CompleteBooking)($booking)->status)->toBe(BookingStatus::Completed)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Completed);
});

it('marks a live booking as a no-show (R23)', function (): void {
    $booking = Booking::factory()->booked()->bookedFor(user('Alice'))->create();

    expect((new MarkNoShow)($booking)->status)->toBe(BookingStatus::NoShow)
        ->and($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('reclassifies between completed and no-show in both directions', function (): void {
    $booking = Booking::factory()->completed()->bookedFor(user('Alice'))->create();

    expect((new MarkNoShow)($booking)->status)->toBe(BookingStatus::NoShow)
        ->and((new CompleteBooking)($booking->fresh())->status)->toBe(BookingStatus::Completed);
});

it('refuses to re-apply the same outcome', function (): void {
    $completed = Booking::factory()->completed()->create();
    $noShow = Booking::factory()->noShow()->create();

    expect(fn (): Booking => (new CompleteBooking)($completed))->toThrow(InvalidTransition::class)
        ->and(fn (): Booking => (new MarkNoShow)($noShow))->toThrow(InvalidTransition::class);
});

it('refuses either outcome on a cancelled booking (cancelled is terminal)', function (): void {
    $booking = Booking::factory()->cancelled()->create();

    expect(fn (): Booking => (new CompleteBooking)($booking))->toThrow(InvalidTransition::class)
        ->and(fn (): Booking => (new MarkNoShow)($booking))->toThrow(InvalidTransition::class);
});

it('leaves the slot untouched', function (): void {
    $slot = Slot::factory()->booked()->create();
    $booking = Booking::factory()->for($slot, 'slot')->booked()->bookedFor(user('Alice'))->create();

    (new CompleteBooking)($booking);

    expect($slot->fresh()->status)->toBe(SlotStatus::Booked);
});
