<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingCancelled;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\ReleaseSlot;

/**
 * Cancel a live claim and hand its slot back per the origin rule (D3).
 */
final class CancelBooking
{
    public function __invoke(Booking $booking, ?Model $cancelledBy = null): Booking
    {
        return DB::transaction(function () use ($booking, $cancelledBy): Booking {
            // The caller's copy may predate someone else's cancellation.
            $booking->refresh();

            $from = $booking->status;

            if (! $from->canTransitionTo(BookingStatus::Cancelled)) {
                throw InvalidTransition::for($booking, $from, BookingStatus::Cancelled);
            }

            $booking->status = BookingStatus::Cancelled;
            $booking->cancelled_at = CarbonImmutable::now('UTC');
            $booking->cancelled_by_type = $cancelledBy?->getMorphClass();
            $booking->cancelled_by_id = $cancelledBy instanceof Model ? (string) $cancelledBy->getKey() : null;
            $booking->save();

            $slot = $booking->slot;

            if ($slot instanceof Slot) {
                (new ReleaseSlot)($slot);
            }

            $booking->load(['slot', 'hosts', 'bookedFor', 'bookedBy', 'cancelledBy']);

            DB::afterCommit(fn () => event(new BookingCancelled($booking)));

            return $booking;
        });
    }
}
