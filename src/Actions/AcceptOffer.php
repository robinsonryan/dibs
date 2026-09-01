<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\BookingOptions;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Events\OfferAccepted;
use RobinsonRyan\Dibs\Exceptions\OfferNotAcceptable;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\ReleaseSlot;

/**
 * The invitee picks one of the offered times (§5.3). The offer row is locked
 * first, so a sweep, a withdrawal and a second acceptance all serialise behind
 * this one — the hold is released exactly once, by whoever wins the row.
 */
final class AcceptOffer
{
    public function __invoke(Offer $offer, Slot $chosenSlot, ?Model $bookedBy = null): Booking
    {
        return DB::transaction(function () use ($offer, $chosenSlot, $bookedBy): Booking {
            $locked = Dibs::lock($offer);

            if (! $locked instanceof Offer) {
                throw OfferNotAcceptable::for($offer, 'it no longer exists');
            }

            if ($locked->status !== OfferStatus::Pending) {
                throw OfferNotAcceptable::for($locked, sprintf('its status is %s', $locked->status->value));
            }

            // Measured against the clock, whether or not a sweep has run (R29).
            if ($locked->isExpired()) {
                throw OfferNotAcceptable::for($locked, 'it has expired');
            }

            $locked->load(['slots', 'offeredTo']);

            $chosen = $locked->slots->first(
                fn (Slot $slot): bool => $slot->getKey() === $chosenSlot->getKey(),
            );

            if (! $chosen instanceof Slot) {
                throw OfferNotAcceptable::for($locked, 'the chosen slot is not one of its slots');
            }

            $invitee = $locked->offeredTo;

            if (! $invitee instanceof Model) {
                throw OfferNotAcceptable::for($locked, 'the party it was offered to no longer exists');
            }

            // An outstanding offer is a promise: the offer path relaxes the
            // closed availability and the notice/horizon window (D11).
            // The offer's own scope wins; an offer without one leaves BookSlot
            // to inherit the availability's.
            $booking = (new BookSlot)($chosen, $invitee, $bookedBy ?? $invitee, new BookingOptions(
                viaOffer: true,
                contextType: $locked->context_type,
                contextId: $locked->context_id,
            ));

            foreach ($locked->slots as $slot) {
                if ($slot->getKey() !== $chosen->getKey()) {
                    (new ReleaseSlot)($slot);
                }
            }

            $locked->status = OfferStatus::Accepted;
            $locked->accepted_booking_id = (string) $booking->getKey();
            $locked->save();

            $locked->load(['slots', 'offeredTo', 'acceptedBooking']);

            DB::afterCommit(fn () => event(new OfferAccepted($locked, $booking)));

            return $booking;
        });
    }
}
