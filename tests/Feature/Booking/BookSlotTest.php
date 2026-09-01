<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Data\BookingOptions;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * A consumer's extended booking model (R35): the package must return this one
 * when the class-map names it.
 */
final class BookSlotTestBooking extends Booking {}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('books an open future slot on a published availability', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');

    $booking = (new BookSlot)($slot, $alice, $alice);

    expect($booking->status)->toBe(BookingStatus::Booked)
        ->and($booking->slot_id)->toBe($slot->id)
        ->and($booking->booked_for_type)->toBe('user')
        ->and($booking->booked_for_id)->toBe((string) $alice->getKey())
        ->and($booking->booked_by_id)->toBe((string) $alice->getKey())
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('records the submitter separately when booking on someone else’s behalf', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $clerk = user('Clerk');

    $booking = (new BookSlot)($slot, $alice, $clerk);

    expect($booking->booked_for_id)->toBe((string) $alice->getKey())
        ->and($booking->booked_by_id)->toBe((string) $clerk->getKey());
});

it('refuses a held slot, and accepts it on the offer path', function (): void {
    $slot = Slot::factory()->held()->create();
    $alice = user('Alice');

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    $booking = (new BookSlot)($slot->fresh(), $alice, $alice, new BookingOptions(viaOffer: true));

    expect($booking->status)->toBe(BookingStatus::Booked)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('refuses a slot on a draft availability, even on the offer path', function (): void {
    $slot = Slot::factory()->for(Availability::factory()->draft())->create();
    $alice = user('Alice');

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice, new BookingOptions(viaOffer: true)))
        ->toThrow(SlotUnavailable::class);
});

it('refuses a slot on a closed availability, and accepts it on the offer path (D11)', function (): void {
    $slot = Slot::factory()->for(Availability::factory()->closed())->create();
    $alice = user('Alice');

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    $booking = (new BookSlot)($slot->fresh(), $alice, $alice, new BookingOptions(viaOffer: true));

    expect($booking->status)->toBe(BookingStatus::Booked);
});

it('refuses a slot that has already started, even on the offer path', function (): void {
    $slot = Slot::factory()->past()->create();
    $alice = user('Alice');

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice, new BookingOptions(viaOffer: true)))
        ->toThrow(SlotUnavailable::class);
});

it('enforces minimum notice, and skips it on the offer path (D11)', function (): void {
    $availability = Availability::factory()->published()->notice(120)->create();
    $slot = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addMinutes(30))->create();
    $alice = user('Alice');

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    $booking = (new BookSlot)($slot->fresh(), $alice, $alice, new BookingOptions(viaOffer: true));

    expect($booking->status)->toBe(BookingStatus::Booked);
});

it('accepts a slot exactly on the minimum-notice boundary', function (): void {
    $availability = Availability::factory()->published()->notice(120)->create();
    $slot = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addMinutes(120))->create();
    $alice = user('Alice');

    expect((new BookSlot)($slot, $alice, $alice)->status)->toBe(BookingStatus::Booked);
});

it('enforces the maximum horizon, and skips it on the offer path (D11)', function (): void {
    $availability = Availability::factory()->published()->notice(null, 7)->create();
    $slot = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addDays(30))->create();
    $alice = user('Alice');

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    $booking = (new BookSlot)($slot->fresh(), $alice, $alice, new BookingOptions(viaOffer: true));

    expect($booking->status)->toBe(BookingStatus::Booked);
});

it('applies neither notice nor horizon to an adhoc slot', function (): void {
    $slot = Slot::factory()->adhoc()->at(CarbonImmutable::now('UTC')->addMinutes(5))->create();
    $alice = user('Alice');

    expect((new BookSlot)($slot, $alice, $alice)->status)->toBe(BookingStatus::Booked);
});

it('refuses a slot that is already full', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $bob = user('Bob');
    (new BookSlot)($slot, $alice, $alice);

    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $bob, $bob))
        ->toThrow(SlotUnavailable::class);
});

it('flips a capacity-3 slot to booked only on the third booking, then refuses a fourth', function (): void {
    $slot = Slot::factory()->capacity(3)->create();

    $alice = user('Alice');
    $bob = user('Bob');
    $carol = user('Carol');
    $dave = user('Dave');

    (new BookSlot)($slot->fresh(), $alice, $alice);
    expect($slot->fresh()->status)->toBe(SlotStatus::Open);

    (new BookSlot)($slot->fresh(), $bob, $bob);
    expect($slot->fresh()->status)->toBe(SlotStatus::Open);

    (new BookSlot)($slot->fresh(), $carol, $carol);
    expect($slot->fresh()->status)->toBe(SlotStatus::Booked)
        ->and(Booking::active()->count())->toBe(3);

    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $dave, $dave))
        ->toThrow(SlotUnavailable::class);
});

it('refuses a second live claim on one slot by the same party', function (): void {
    $slot = Slot::factory()->capacity(2)->create();
    $alice = user('Alice');

    (new BookSlot)($slot->fresh(), $alice, $alice);

    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $alice, $alice))
        ->toThrow(SlotUnavailable::class);

    expect(Booking::active()->count())->toBe(1)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Open);
});

it('denormalises the availability type onto the booking and keeps it after an availability edit (D13)', function (): void {
    $availability = Availability::factory()->published()->create(['type' => 'temple-recommend']);
    $slot = Slot::factory()->for($availability)->create();
    $alice = user('Alice');

    $booking = (new BookSlot)($slot, $alice, $alice);
    $availability->update(['type' => 'tithing-settlement']);

    expect($booking->fresh()->type)->toBe('temple-recommend');
});

it('lets the caller override the booking type', function (): void {
    $availability = Availability::factory()->published()->create(['type' => 'temple-recommend']);
    $slot = Slot::factory()->for($availability)->create();
    $alice = user('Alice');

    $booking = (new BookSlot)($slot, $alice, $alice, new BookingOptions(type: 'other'));

    expect($booking->type)->toBe('other');
});

it('stores the caller’s meta payload', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');

    $booking = (new BookSlot)($slot, $alice, $alice, new BookingOptions(meta: ['note' => 'front desk']));

    expect($booking->fresh()->meta)->toBe(['note' => 'front desk']);
});

it('returns the consumer’s extended booking model (R35)', function (): void {
    config()->set('dibs.models.'.Booking::class, BookSlotTestBooking::class);

    $slot = Slot::factory()->create();
    $alice = user('Alice');

    expect((new BookSlot)($slot, $alice, $alice))->toBeInstanceOf(BookSlotTestBooking::class);
});

it('books a slot whose in-memory copy is stale about its status', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');

    // The caller holds an open copy; the row has since been filled.
    Slot::query()->whereKey($slot->id)->update(['status' => SlotStatus::Booked->value]);

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))
        ->toThrow(SlotUnavailable::class);
});
