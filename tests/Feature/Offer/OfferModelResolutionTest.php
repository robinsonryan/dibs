<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;

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
