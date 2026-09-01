<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\AcceptOffer;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Actions\WithdrawOffer;
use RobinsonRyan\Dibs\Events\OfferAccepted;
use RobinsonRyan\Dibs\Events\OfferCreated;
use RobinsonRyan\Dibs\Events\OfferExpired;
use RobinsonRyan\Dibs\Events\OfferWithdrawn;
use RobinsonRyan\Dibs\Exceptions\SlotNotOfferable;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Records every dispatch of $event with the transaction depth it fired at. The
 * test itself runs inside one transaction (RefreshDatabase), so depth 1 means
 * the action's own transaction had already committed (R33).
 *
 * @param  class-string  $event
 */
function recordOfferDispatches(string $event): stdClass
{
    $recorded = new stdClass;
    $recorded->events = [];
    $recorded->depths = [];

    Event::listen($event, function (object $fired) use ($recorded): void {
        $recorded->events[] = $fired;
        $recorded->depths[] = DB::transactionLevel();
    });

    return $recorded;
}

it('fires OfferCreated after commit with its relations loaded (R33)', function (): void {
    $invitee = user('Invitee');
    $bishop = user('Bishop');
    $slot = Slot::factory()->create();

    $recorded = recordOfferDispatches(OfferCreated::class);

    (new CreateOffer)($invitee, [$slot], null, $bishop);

    expect($recorded->depths)->toBe([1]);

    $offer = $recorded->events[0]->offer;

    expect($offer->relationLoaded('slots'))->toBeTrue()
        ->and($offer->slots)->toHaveCount(1)
        ->and($offer->relationLoaded('offeredTo'))->toBeTrue()
        ->and($offer->offeredTo->getKey())->toBe($invitee->getKey())
        ->and($offer->relationLoaded('createdBy'))->toBeTrue()
        ->and($offer->createdBy->getKey())->toBe($bishop->getKey());
});

it('does not fire OfferCreated when a slot is refused', function (): void {
    $recorded = recordOfferDispatches(OfferCreated::class);

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [Slot::factory()->capacity(2)->create()]))
        ->toThrow(SlotNotOfferable::class)
        ->and($recorded->events)->toBe([]);
});

it('fires OfferAccepted after commit carrying the offer and its booking (R33)', function (): void {
    $invitee = user('Invitee');
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)($invitee, [$slot]);

    $recorded = recordOfferDispatches(OfferAccepted::class);

    $booking = (new AcceptOffer)($offer, $slot);

    expect($recorded->depths)->toBe([1]);

    $fired = $recorded->events[0];

    expect($fired->booking->getKey())->toBe($booking->getKey())
        ->and($fired->offer->relationLoaded('slots'))->toBeTrue()
        ->and($fired->offer->relationLoaded('offeredTo'))->toBeTrue()
        ->and($fired->offer->offeredTo->getKey())->toBe($invitee->getKey())
        ->and($fired->offer->relationLoaded('acceptedBooking'))->toBeTrue()
        ->and($fired->offer->acceptedBooking->getKey())->toBe($booking->getKey());
});

it('fires OfferWithdrawn after commit with its relations loaded (R33)', function (): void {
    $invitee = user('Invitee');
    $offer = (new CreateOffer)($invitee, [Slot::factory()->create()]);

    $recorded = recordOfferDispatches(OfferWithdrawn::class);

    (new WithdrawOffer)($offer);

    expect($recorded->depths)->toBe([1]);

    $fired = $recorded->events[0]->offer;

    expect($fired->relationLoaded('slots'))->toBeTrue()
        ->and($fired->relationLoaded('offeredTo'))->toBeTrue()
        ->and($fired->offeredTo->getKey())->toBe($invitee->getKey());
});

it('fires one OfferExpired per swept offer, after commit, with its relations loaded (R33)', function (): void {
    $invitee = user('Invitee');
    (new CreateOffer)($invitee, [Slot::factory()->create()], CarbonImmutable::now('UTC')->addHour());
    (new CreateOffer)(user('Other'), [Slot::factory()->create()], CarbonImmutable::now('UTC')->addHour());

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(2));

    $recorded = recordOfferDispatches(OfferExpired::class);

    expect((new ExpireOffers)())->toBe(2)
        ->and($recorded->depths)->toBe([1, 1]);

    $fired = $recorded->events[0]->offer;

    expect($fired->relationLoaded('slots'))->toBeTrue()
        ->and($fired->relationLoaded('offeredTo'))->toBeTrue();
});
