<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\AcceptOffer;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Actions\WithdrawOffer;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\OfferExpired;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;

afterEach(function (): void {
    DB::setDefaultConnection('testing');

    foreach (['testing', 'testing_b'] as $name) {
        $connection = DB::connection($name);

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $connection->statement('set lock_timeout = 0');
        $connection->flushQueryLog();
        $connection->disableQueryLog();
    }

    DB::connection('testing')->statement(
        'TRUNCATE dibs_booking_hosts, dibs_bookings, dibs_offer_slots, dibs_offers, '.
        'dibs_availability_hosts, dibs_slots, dibs_availabilities, '.
        'fixture_users, fixture_rooms, fixture_organizations CASCADE',
    );
});

/**
 * Every statement the connection got an answer to. A statement the lock timeout
 * cancelled never lands here, so an empty list means the action blocked on its
 * very first query.
 *
 * @return list<string>
 */
function offerStatements(Connection $connection): array
{
    $queries = [];

    foreach ($connection->getQueryLog() as $entry) {
        $queries[] = is_string($entry['query'] ?? null) ? $entry['query'] : '';
    }

    return $queries;
}

/**
 * @return list<string>
 */
function offerWrites(Connection $connection): array
{
    $writes = [];

    foreach ($connection->getQueryLog() as $entry) {
        $query = is_string($entry['query'] ?? null) ? $entry['query'] : '';

        if (preg_match('/^\s*(insert|update|delete)\b/i', $query) === 1) {
            $writes[] = $query;
        }
    }

    return $writes;
}

/**
 * An offer on one held slot, committed, with an hour to run.
 *
 * @return array{0: Offer, 1: Slot}
 */
function pendingOffer(string $invitee = 'Invitee'): array
{
    $slot = Slot::factory()->create();
    $offer = (new CreateOffer)(user($invitee), [$slot], CarbonImmutable::now('UTC')->addHour());

    return [$offer, $slot];
}

/**
 * Session A takes the offer row and keeps it; session B is given a short
 * lock timeout and becomes the default connection.
 */
function contendForOffer(Offer $offer): Connection
{
    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    $a->beginTransaction();
    $a->select('select id from dibs_offers where id = ? for update', [$offer->id]);

    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    return $b;
}

it('makes the sweep wait for whoever holds the offer row, writing nothing while it waits', function (): void {
    [$offer, $slot] = pendingOffer();
    $b = contendForOffer($offer);

    try {
        (new ExpireOffers)(CarbonImmutable::now('UTC')->addHours(2));
        $this->fail('Expected the sweep to block on the offer row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // The sweep lists its candidates before locking any of them, so the bar here
    // is that nothing was *written* from that unlocked listing.
    expect(offerWrites($b))->toBe([]);

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    DB::connection('testing')->rollBack();

    expect($offer->fresh()?->status)->toBe(OfferStatus::Pending)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Held);
});

it('makes an acceptance wait for whoever holds the offer row, writing nothing while it waits', function (): void {
    [$offer, $slot] = pendingOffer();
    $b = contendForOffer($offer);

    try {
        (new AcceptOffer)($offer, $slot);
        $this->fail('Expected the acceptance to block on the offer row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // In particular: no booking was written from a stale read of the offer.
    expect(offerStatements($b))->toBe([]);

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    DB::connection('testing')->rollBack();

    expect(Booking::query()->count())->toBe(0)
        ->and($offer->fresh()?->status)->toBe(OfferStatus::Pending)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Held);
});

it('makes a withdrawal wait for whoever holds the offer row, writing nothing while it waits', function (): void {
    [$offer, $slot] = pendingOffer();
    $b = contendForOffer($offer);

    try {
        (new WithdrawOffer)($offer);
        $this->fail('Expected the withdrawal to block on the offer row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    expect(offerStatements($b))->toBe([]);

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    DB::connection('testing')->rollBack();

    expect($offer->fresh()?->status)->toBe(OfferStatus::Pending)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Held);
});

it('expires the rest of the sweep when one offer cannot be settled, then reports the failure', function (): void {
    // The blocked one is swept first: the sweep works longest-overdue first.
    $blockedSlot = Slot::factory()->create();
    $blocked = (new CreateOffer)(user('First'), [$blockedSlot], CarbonImmutable::now('UTC')->addHour());

    $otherSlot = Slot::factory()->create();
    $other = (new CreateOffer)(user('Second'), [$otherSlot], CarbonImmutable::now('UTC')->addHours(2));

    $b = contendForOffer($blocked);

    try {
        (new ExpireOffers)(CarbonImmutable::now('UTC')->addHours(3));
        $this->fail('Expected the sweep to report the offer it could not settle.');
    } catch (QueryException $blockedException) {
        expect((string) $blockedException->getCode())->toBe('55P03');
    }

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    DB::connection('testing')->rollBack();

    // One offer stopped the sweep from being clean; it did not stop the sweep.
    expect($other->fresh()?->status)->toBe(OfferStatus::Expired)
        ->and($otherSlot->fresh()?->status)->toBe(SlotStatus::Open)
        ->and($blocked->fresh()?->status)->toBe(OfferStatus::Pending)
        ->and($blockedSlot->fresh()?->status)->toBe(SlotStatus::Held);
});

it('leaves an offer withdrawn between the sweep’s listing and its lock alone', function (): void {
    [$offer, $slot] = pendingOffer();

    $fired = 0;
    Event::listen(OfferExpired::class, function () use (&$fired): void {
        $fired++;
    });

    // Withdraw it the moment the sweep hydrates it, before the sweep locks it:
    // the post-lock status re-check is the only thing standing in the way of a
    // second release.
    $intervened = false;
    Offer::retrieved(function (Offer $retrieved) use (&$intervened): void {
        if ($intervened) {
            return;
        }

        $intervened = true;

        (new WithdrawOffer)($retrieved);
    });

    expect((new ExpireOffers)(CarbonImmutable::now('UTC')->addHours(2)))->toBe(0)
        ->and($intervened)->toBeTrue()
        ->and($offer->fresh()?->status)->toBe(OfferStatus::Withdrawn)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Open)
        ->and($fired)->toBe(0);
});
