<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\AcceptOffer;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\WithdrawOffer;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('withdraws a pending offer and hands every availability-born slot back to open (R30)', function (): void {
    $availability = Availability::factory()->published()->create();
    $first = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addDays(7)->startOfHour())->create();
    $second = Slot::factory()->for($availability)->at(CarbonImmutable::now('UTC')->addDays(8)->startOfHour())->create();

    $offer = (new CreateOffer)(user('Invitee'), [$first, $second]);

    $withdrawn = (new WithdrawOffer)($offer);

    expect($withdrawn->status)->toBe(OfferStatus::Withdrawn)
        ->and($first->refresh()->status)->toBe(SlotStatus::Open)
        ->and($second->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Slot::query()->bookable()->count())->toBe(2);
});

it('deletes an adhoc slot no booking ever touched when the offer is withdrawn (R30, D3)', function (): void {
    $start = CarbonImmutable::now('UTC')->addDays(4)->startOfHour();
    $offer = (new CreateOffer)(user('Invitee'), [new AdhocSlotSpec($start, $start->addMinutes(30), 'Bishop office')]);
    $adhoc = $offer->slots->first();

    (new WithdrawOffer)($offer);

    expect(Slot::query()->whereKey($adhoc?->getKey())->exists())->toBeFalse()
        ->and(OfferSlot::query()->count())->toBe(0)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Withdrawn);
});

it('refuses to withdraw an offer that is no longer pending (R30)', function (): void {
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)(user('Invitee'), [$slot]);
    (new AcceptOffer)($offer, $slot);

    expect(fn (): Offer => (new WithdrawOffer)($offer))->toThrow(InvalidTransition::class)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Accepted)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Booked);
});

it('refuses to withdraw an already withdrawn offer', function (): void {
    $offer = (new CreateOffer)(user('Invitee'), [Slot::factory()->create()]);
    (new WithdrawOffer)($offer);

    expect(fn (): Offer => (new WithdrawOffer)($offer->refresh()))->toThrow(InvalidTransition::class);
});
