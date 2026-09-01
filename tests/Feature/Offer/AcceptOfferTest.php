<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\AcceptOffer;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Actions\CloseAvailability;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\WithdrawOffer;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\OfferNotAcceptable;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function adhocSpec(int $daysAhead = 4): AdhocSlotSpec
{
    $start = CarbonImmutable::now('UTC')->addDays($daysAhead)->startOfHour();

    return new AdhocSlotSpec($start, $start->addMinutes(30), 'Bishop office');
}

it('books the chosen slot for the invitee and returns availability-born losers to open (R27)', function (): void {
    $invitee = user('Invitee');
    $availability = Availability::factory()->published()->create();
    $chosen = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addDays(7)->startOfHour())->create();
    $loser = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addDays(8)->startOfHour())->create();

    $offer = (new CreateOffer)($invitee, [$chosen, $loser]);

    $booking = (new AcceptOffer)($offer, $chosen);

    $offer->refresh();

    expect($booking->status)->toBe(BookingStatus::Booked)
        ->and($booking->slot_id)->toBe($chosen->getKey())
        ->and($booking->booked_for_id)->toBe((string) $invitee->getKey())
        ->and($booking->booked_by_id)->toBe((string) $invitee->getKey())
        ->and($chosen->refresh()->status)->toBe(SlotStatus::Booked)
        ->and($loser->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Slot::query()->bookable()->pluck('id')->all())->toBe([$loser->id])
        ->and($offer->status)->toBe(OfferStatus::Accepted)
        ->and($offer->accepted_booking_id)->toBe($booking->getKey());
});

it('deletes an adhoc loser that no booking ever touched, cascading its offer slot row (R27, D3)', function (): void {
    $invitee = user('Invitee');
    $chosen = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$chosen, adhocSpec()]);
    $adhoc = $offer->slots->first(fn (Slot $slot): bool => $slot->isAdhoc());

    (new AcceptOffer)($offer, $chosen);

    expect(Slot::query()->whereKey($adhoc?->getKey())->exists())->toBeFalse()
        ->and(OfferSlot::query()->where('offer_id', $offer->getKey())->count())->toBe(1)
        ->and($chosen->refresh()->status)->toBe(SlotStatus::Booked);
});

it('accepts the adhoc slot of a mixed offer and hands the availability-born one back', function (): void {
    $invitee = user('Invitee');
    $existing = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$existing, adhocSpec()]);
    $adhoc = $offer->slots->first(fn (Slot $slot): bool => $slot->isAdhoc());

    $booking = (new AcceptOffer)($offer, $adhoc);

    expect($booking->slot_id)->toBe($adhoc?->getKey())
        ->and($adhoc?->refresh()->status)->toBe(SlotStatus::Booked)
        ->and($existing->refresh()->status)->toBe(SlotStatus::Open);
});

it('honours an outstanding offer on a since-closed availability (R28, D11)', function (): void {
    $invitee = user('Invitee');
    $availability = Availability::factory()->published()->create();
    $slot = Slot::factory()->for($availability)->create();

    $offer = (new CreateOffer)($invitee, [$slot]);
    (new CloseAvailability)($availability);

    $booking = (new AcceptOffer)($offer, $slot);

    expect($booking->status)->toBe(BookingStatus::Booked)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Booked);
});

it('ignores minimum notice and maximum horizon on the offer path (R28, D11)', function (): void {
    $invitee = user('Invitee');
    $availability = Availability::factory()->published()->notice(60 * 24 * 30, 1)->create();
    $slot = Slot::factory()->for($availability)->create();

    // The same slot is unbookable to anyone walking up to it.
    expect(fn (): Booking => (new BookSlot)($slot, $invitee, $invitee))->toThrow(SlotUnavailable::class);

    $offer = (new CreateOffer)($invitee, [$slot]);

    expect((new AcceptOffer)($offer, $slot)->status)->toBe(BookingStatus::Booked);
});

it('refuses a pending offer that is past its expiry even though no sweep has run (R29)', function (): void {
    $invitee = user('Invitee');
    $slot = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$slot], CarbonImmutable::now('UTC')->addHour());

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    expect(fn (): Booking => (new AcceptOffer)($offer, $slot))->toThrow(OfferNotAcceptable::class)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Pending)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Held)
        ->and(Booking::query()->count())->toBe(0);
});

it('refuses a withdrawn offer (R28)', function (): void {
    $invitee = user('Invitee');
    $slot = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$slot]);
    (new WithdrawOffer)($offer);

    expect(fn (): Booking => (new AcceptOffer)($offer, $slot))->toThrow(OfferNotAcceptable::class)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Withdrawn)
        ->and(Booking::query()->count())->toBe(0);
});

it('refuses an offer that has already been accepted (R28)', function (): void {
    $invitee = user('Invitee');
    $first = Slot::factory()->create();
    $second = Slot::factory()->at(CarbonImmutable::now('UTC')->addDays(9)->startOfHour())->create();

    $offer = (new CreateOffer)($invitee, [$first, $second]);
    $booking = (new AcceptOffer)($offer, $first);

    expect(fn (): Booking => (new AcceptOffer)($offer, $second))->toThrow(OfferNotAcceptable::class)
        ->and(Booking::query()->count())->toBe(1)
        ->and($offer->refresh()->accepted_booking_id)->toBe($booking->getKey())
        ->and($second->refresh()->status)->toBe(SlotStatus::Open);
});

it('refuses a slot that is not part of the offer, leaving both slots as they were (R28)', function (): void {
    $invitee = user('Invitee');
    $offered = Slot::factory()->create();
    $stranger = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$offered]);

    expect(fn (): Booking => (new AcceptOffer)($offer, $stranger))->toThrow(OfferNotAcceptable::class)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Pending)
        ->and($offered->refresh()->status)->toBe(SlotStatus::Held)
        ->and($stranger->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Booking::query()->count())->toBe(0);
});

it('books for the invitee while recording who acted on their behalf', function (): void {
    $invitee = user('Invitee');
    $clerk = user('Clerk');
    $slot = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$slot]);
    $booking = (new AcceptOffer)($offer, $slot, $clerk);

    expect($booking->booked_for_id)->toBe((string) $invitee->getKey())
        ->and($booking->booked_for_type)->toBe('user')
        ->and($booking->booked_by_id)->toBe((string) $clerk->getKey());
});

it('refuses a slot that has since been booked by someone else', function (): void {
    $invitee = user('Invitee');
    $slot = Slot::factory()->capacity(1)->create();

    $offer = (new CreateOffer)($invitee, [$slot]);

    // Someone with an offer path of their own claims the same held slot first.
    $rival = (new CreateOffer)(user('Rival'), [Slot::factory()->create()]);
    OfferSlot::query()->create(['offer_id' => $rival->getKey(), 'slot_id' => $slot->getKey()]);
    (new AcceptOffer)($rival->refresh(), $slot);

    expect(fn (): Booking => (new AcceptOffer)($offer, $slot))->toThrow(SlotUnavailable::class)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Pending);
});

it('carries the offer’s context onto the booking it creates (R40)', function (): void {
    $ward = organization('Oak Hills');
    $invitee = user('Invitee');
    $chosen = Slot::factory()->create();

    $offer = (new CreateOffer)($invitee, [$chosen], null, null, null, [], $ward);

    $booking = (new AcceptOffer)($offer, $chosen);

    expect($booking->context_type)->toBe('organization')
        ->and($booking->context_id)->toBe((string) $ward->getKey())
        ->and(Booking::forContext($ward)->pluck('id')->all())->toBe([$booking->id]);
});

it('keeps the offer’s stored context even when that tenant row is gone', function (): void {
    $ward = organization('Oak Hills');
    $other = organization('Riverside');
    $availability = Availability::factory()->published()->forContext($other)->create();
    $chosen = Slot::factory()->for($availability)->create();
    $offer = (new CreateOffer)(user('Invitee'), [$chosen], context: $ward);
    $wardId = (string) $ward->getKey();

    $ward->delete();

    $booking = (new AcceptOffer)($offer, $chosen);

    expect($booking->context_type)->toBe('organization')
        ->and($booking->context_id)->toBe($wardId)
        ->and($booking->context_id)->not->toBe((string) $other->getKey());
});

it('falls back to the availability’s context when the offer carries none', function (): void {
    $ward = organization('Oak Hills');
    $availability = Availability::factory()->published()->forContext($ward)->create();
    $chosen = Slot::factory()->for($availability)->create();

    $offer = (new CreateOffer)(user('Invitee'), [$chosen]);

    $booking = (new AcceptOffer)($offer, $chosen);

    expect($booking->context_id)->toBe((string) $ward->getKey())
        ->and($offer->fresh()?->context_id)->toBeNull();
});
