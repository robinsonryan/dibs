<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Events\OfferWithdrawn;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\ReleaseSlot;

/**
 * Take an outstanding invitation back (§5.3): `pending → withdrawn`, every held
 * slot handed back per the origin rule (D3).
 */
final class WithdrawOffer
{
    public function __invoke(Offer $offer): Offer
    {
        return DB::transaction(function () use ($offer): Offer {
            $locked = Dibs::query(Offer::class)
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->first();

            // A row that is no longer there cannot move anywhere.
            if (! $locked instanceof Offer) {
                throw InvalidTransition::for($offer, $offer->status, OfferStatus::Withdrawn);
            }

            $from = $locked->status;

            if (! $from->canTransitionTo(OfferStatus::Withdrawn)) {
                throw InvalidTransition::for($locked, $from, OfferStatus::Withdrawn);
            }

            $locked->load('slots');

            foreach ($locked->slots as $slot) {
                (new ReleaseSlot)($slot);
            }

            $locked->status = OfferStatus::Withdrawn;
            $locked->save();

            $locked->load(['slots', 'offeredTo']);

            DB::afterCommit(fn () => event(new OfferWithdrawn($locked)));

            return $locked;
        });
    }
}
