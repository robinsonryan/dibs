<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\AcceptOffer;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\OfferExpired;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('expires every overdue pending offer, releasing its slots (R31)', function (): void {
    $overdue = Slot::factory()->create();
    $expiring = (new CreateOffer)(user('One'), [$overdue], CarbonImmutable::now('UTC')->addHour());

    $safe = Slot::factory()->create();
    $staying = (new CreateOffer)(user('Two'), [$safe], CarbonImmutable::now('UTC')->addDays(2));

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    expect((new ExpireOffers)())->toBe(1)
        ->and($expiring->refresh()->status)->toBe(OfferStatus::Expired)
        ->and($overdue->refresh()->status)->toBe(SlotStatus::Open)
        ->and($staying->refresh()->status)->toBe(OfferStatus::Pending)
        ->and($safe->refresh()->status)->toBe(SlotStatus::Held);
});

it('never expires an offer without an expiry (R31)', function (): void {
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)(user('Invitee'), [$slot]);

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addYears(2));

    expect((new ExpireOffers)())->toBe(0)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Pending)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Held);
});

it('is idempotent: a second sweep expires nothing, fires nothing and releases nothing twice (R31)', function (): void {
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::now('UTC')->addHour());

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    $fired = 0;
    Event::listen(OfferExpired::class, function () use (&$fired): void {
        $fired++;
    });

    expect((new ExpireOffers)())->toBe(1)
        ->and((new ExpireOffers)())->toBe(0)
        ->and($fired)->toBe(1)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Expired)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Open);
});

it('leaves an offer accepted before the sweep alone (R31)', function (): void {
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::now('UTC')->addHour());
    (new AcceptOffer)($offer, $slot);

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    expect((new ExpireOffers)())->toBe(0)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Accepted)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Booked);
});

it('sweeps against a supplied instant rather than the clock (R31, D10)', function (): void {
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::now('UTC')->addHour());

    expect((new ExpireOffers)())->toBe(0)
        ->and((new ExpireOffers)(CarbonImmutable::now('UTC')->addHours(3)))->toBe(1)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Expired);
});

it('deletes an untouched adhoc slot when its offer expires (R31, D3)', function (): void {
    $start = CarbonImmutable::now('UTC')->addDays(4)->startOfHour();
    $offer = (new CreateOffer)(
        user('Invitee'),
        [new AdhocSlotSpec($start, $start->addMinutes(30), 'Bishop office')],
        CarbonImmutable::now('UTC')->addHour(),
    );
    $adhoc = $offer->slots->first();

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    expect((new ExpireOffers)())->toBe(1)
        ->and(Slot::query()->whereKey($adhoc?->getKey())->exists())->toBeFalse();
});

it('expires several overdue offers in one sweep and returns the count (R31)', function (): void {
    $offers = collect(range(1, 3))->map(fn (int $index): Offer => (new CreateOffer)(
        user('Invitee '.$index),
        [Slot::factory()->create()],
        CarbonImmutable::now('UTC')->addHour(),
    ));

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    expect((new ExpireOffers)())->toBe(3)
        ->and(Offer::query()->where('status', OfferStatus::Expired->value)->count())->toBe(3)
        ->and(Slot::query()->open()->count())->toBe(3);
});
