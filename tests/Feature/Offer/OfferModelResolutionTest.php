<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * A consumer's extended offer, substituted via config('dibs.models').
 */
final class OfferWithExtras extends Offer
{
    public function shout(): string
    {
        return 'extended';
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
    config()->set('dibs.models.'.Offer::class, OfferWithExtras::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('creates and sweeps a consumer\'s extended offer model (R35)', function (): void {
    $offer = (new CreateOffer)(user('Invitee'), [Slot::factory()->create()], CarbonImmutable::now('UTC')->addHour());

    expect($offer)->toBeInstanceOf(OfferWithExtras::class)
        ->and($offer->shout())->toBe('extended');

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    expect((new ExpireOffers)())->toBe(1)
        ->and($offer->refresh()->status)->toBe(OfferStatus::Expired);
});

it('locks a row back into the consumer\'s extended model, whichever class the caller holds (R35)', function (): void {
    $offer = (new CreateOffer)(user('Invitee'), [Slot::factory()->create()]);

    DB::transaction(function () use ($offer): void {
        // Handed the extended class the consumer already has...
        expect(Dibs::lock($offer))->toBeInstanceOf(OfferWithExtras::class);

        // ...and handed a plain package model with the same key, which is what a
        // caller gets from a query that never went through the class-map.
        $plain = (new Offer)->forceFill(['id' => $offer->getKey()]);

        expect(Dibs::lock($plain))->toBeInstanceOf(OfferWithExtras::class);
    });
});

it('returns null from a lock on a row that is no longer there', function (): void {
    $offer = (new CreateOffer)(user('Invitee'), [Slot::factory()->create()]);
    $ghost = $offer->replicate();
    $ghost->id = $offer->getKey();
    Offer::query()->whereKey($offer->getKey())->delete();

    DB::transaction(function () use ($ghost): void {
        expect(Dibs::lock($ghost))->toBeNull();
    });
});
