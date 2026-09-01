<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Events\OfferExpired;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\ReleaseSlot;

/**
 * The sweep a consumer's scheduler runs (§5.3): every pending offer past its
 * expiry lets its slots go per the origin rule (D3). Each offer is settled in
 * its own transaction, so one that cannot be expired never blocks the rest, and
 * the offer row is re-read under lock — an acceptance that won the race is left
 * alone rather than double-released.
 */
final class ExpireOffers
{
    /**
     * @return int number of offers expired in this sweep
     */
    public function __invoke(?CarbonInterface $now = null): int
    {
        $moment = Slot::instant($now);

        $overdue = Dibs::query(Offer::class)
            ->where('status', OfferStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $moment)
            ->get();

        $expired = 0;

        foreach ($overdue as $offer) {
            if ($this->expire($offer, $moment)) {
                $expired++;
            }
        }

        return $expired;
    }

    private function expire(Offer $offer, CarbonImmutable $moment): bool
    {
        return DB::transaction(function () use ($offer, $moment): bool {
            $locked = Dibs::query(Offer::class)
                ->whereKey($offer->getKey())
                ->lockForUpdate()
                ->first();

            // Someone accepted, withdrew or already expired it while we queued.
            if (! $locked instanceof Offer || $locked->status !== OfferStatus::Pending || ! $locked->isExpired($moment)) {
                return false;
            }

            $locked->load('slots');

            foreach ($locked->slots as $slot) {
                (new ReleaseSlot)($slot);
            }

            $locked->status = OfferStatus::Expired;
            $locked->save();

            $locked->load(['slots', 'offeredTo']);

            DB::afterCommit(fn () => event(new OfferExpired($locked)));

            return true;
        });
    }
}
