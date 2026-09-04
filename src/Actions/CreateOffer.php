<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\OfferCreated;
use RobinsonRyan\Dibs\Exceptions\SlotNotOfferable;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Extend a tokenized invitation (§5.3): every slot it names is held for the
 * invitee until they pick one, the offer is withdrawn, or it expires. Holding
 * is all-or-nothing — a refused slot leaves no offer and no held rows behind.
 */
final class CreateOffer
{
    /**
     * @param  iterable<int, Slot|AdhocSlotSpec>  $slots  existing open capacity-1 slots (→ held) and/or adhoc specs (created held)
     * @param  array<string, mixed>  $meta
     * @param  Model|null  $context  the owning scope stamped on the offer, and on the booking accepting it
     */
    public function __invoke(Model $offeredTo, iterable $slots, ?CarbonImmutable $expiresAt = null, ?Model $createdBy = null, ?string $message = null, array $meta = [], ?Model $context = null): Offer
    {
        $this->assertExpiryIsAhead($expiresAt);

        return DB::transaction(function () use ($offeredTo, $slots, $expiresAt, $createdBy, $message, $meta, $context): Offer {
            $held = $this->holdAll($slots);

            if ($held === []) {
                throw new InvalidArgumentException('An offer must name at least one slot.');
            }

            $offer = Dibs::query(Offer::class)->create([
                'token' => $this->token(),
                // Supplied, never inherited: an all-adhoc offer has no
                // availability to inherit a scope from.
                'context_type' => $context?->getMorphClass(),
                'context_id' => $context instanceof Model ? (string) $context->getKey() : null,
                'offered_to_type' => $offeredTo->getMorphClass(),
                'offered_to_id' => (string) $offeredTo->getKey(),
                'created_by_type' => $createdBy?->getMorphClass(),
                'created_by_id' => $createdBy instanceof Model ? (string) $createdBy->getKey() : null,
                'expires_at' => $expiresAt,
                'status' => OfferStatus::Pending,
                'message' => $message,
                'meta' => $meta,
            ]);

            foreach ($held as $slot) {
                Dibs::query(OfferSlot::class)->create([
                    'offer_id' => $offer->getKey(),
                    'slot_id' => $slot->getKey(),
                ]);
            }

            $offer->load(['slots', 'offeredTo', 'createdBy']);

            DB::afterCommit(fn () => event(new OfferCreated($offer)));

            return $offer;
        });
    }

    /**
     * An offer with an expiry already behind it would be dead on arrival.
     */
    private function assertExpiryIsAhead(?CarbonImmutable $expiresAt): void
    {
        if ($expiresAt instanceof CarbonImmutable && $expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new InvalidArgumentException('An offer cannot expire in the past.');
        }
    }

    /**
     * Existing slots first, deduplicated and locked in key order so two offers
     * naming the same pair in opposite orders queue up instead of deadlocking;
     * adhoc specs, which lock nothing, follow.
     *
     * @param  iterable<int, Slot|AdhocSlotSpec>  $slots
     * @return list<Slot>
     */
    private function holdAll(iterable $slots): array
    {
        /** @var array<string, Slot> $existing */
        $existing = [];
        $specs = [];

        foreach ($slots as $slot) {
            if ($slot instanceof Slot) {
                $existing[(string) $slot->getKey()] = $slot;

                continue;
            }

            $specs[] = $slot;
        }

        ksort($existing, SORT_STRING);

        $held = [];

        foreach ($existing as $slot) {
            $held[] = $this->hold($slot);
        }

        foreach ($specs as $spec) {
            $held[] = $this->createAdhoc($spec);
        }

        return $held;
    }

    /**
     * Held exclusively, decided from the slot's locked row so two offers cannot
     * both claim it.
     */
    private function hold(Slot $slot): Slot
    {
        $locked = Dibs::lock($slot);

        if (! $locked instanceof Slot) {
            throw SlotNotOfferable::for($slot, 'it no longer exists');
        }

        // v1 holds whole slots only; one unit of a capacity-N slot is deferred
        // (D12). A pool-derived slot (null column) is held whole as it always
        // was — the hold takes the time, however many of the pool are free (B43).
        if ($locked->capacity !== null && $locked->capacity !== 1) {
            throw SlotNotOfferable::for($locked, 'only a capacity-1 slot can be held by an offer');
        }

        if ($locked->status !== SlotStatus::Open) {
            throw SlotNotOfferable::for($locked, sprintf('its status is %s', $locked->status->value));
        }

        if ($locked->starts_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw SlotNotOfferable::for($locked, 'it has already started');
        }

        $locked->status = SlotStatus::Held;
        $locked->save();

        return $locked;
    }

    /**
     * An invitation time that was never published: an adhoc slot, born held.
     */
    private function createAdhoc(AdhocSlotSpec $spec): Slot
    {
        $spec->ensureValid();

        if ($spec->capacity !== 1) {
            throw new InvalidArgumentException('An offer can only hold a capacity-1 slot; the spec asked for '.$spec->capacity.'.');
        }

        return Dibs::query(Slot::class)->create([
            'availability_id' => null,
            'starts_at' => $spec->startsAt,
            'ends_at' => $spec->endsAt,
            'location' => $spec->location,
            'capacity' => 1,
            'status' => SlotStatus::Held,
        ]);
    }

    /**
     * The only lookup key the link carries, so a misconfigured length can never
     * make it guessable.
     */
    private function token(): string
    {
        $length = config('dibs.token_length', 48);

        return Str::random(max(40, is_int($length) ? $length : 48));
    }
}
