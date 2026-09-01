<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingCancelled;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\ReleaseSlot;

/**
 * Cancel a live claim and hand its slot back per the origin rule (D3).
 */
final class CancelBooking
{
    public function __invoke(Booking $booking, ?Model $cancelledBy = null): Booking
    {
        return DB::transaction(function () use ($booking, $cancelledBy): Booking {
            // Decided from the locked row: a rival cancellation or completion
            // queues behind this one rather than both passing the guard.
            $locked = Dibs::lock($booking);

            if (! $locked instanceof Booking) {
                throw (new ModelNotFoundException)->setModel($booking::class, [$booking->getKey()]);
            }

            $from = $locked->status;

            if (! $from->canTransitionTo(BookingStatus::Cancelled)) {
                throw InvalidTransition::for($locked, $from, BookingStatus::Cancelled);
            }

            $locked->status = BookingStatus::Cancelled;
            $locked->cancelled_at = CarbonImmutable::now('UTC');
            $locked->cancelled_by_type = $cancelledBy?->getMorphClass();
            $locked->cancelled_by_id = $cancelledBy instanceof Model ? (string) $cancelledBy->getKey() : null;
            $locked->save();

            $slot = $locked->slot;

            if ($slot instanceof Slot) {
                (new ReleaseSlot)($slot);
            }

            $locked->load(['slot', 'hosts', 'bookedFor', 'bookedBy', 'cancelledBy']);

            DB::afterCommit(fn () => event(new BookingCancelled($locked)));

            return $locked;
        });
    }
}
