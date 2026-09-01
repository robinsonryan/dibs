<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingMarkedNoShow;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Record that nobody turned up. A post-hoc judgment: it may be swapped with a
 * completion later, and it never touches the slot.
 */
final class MarkNoShow
{
    public function __invoke(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            // Decided from the locked row, so a rival judgment or cancellation
            // queues behind this one.
            $locked = Dibs::lock($booking);

            if (! $locked instanceof Booking) {
                throw (new ModelNotFoundException)->setModel($booking::class, [$booking->getKey()]);
            }

            $from = $locked->status;

            if (! $from->canTransitionTo(BookingStatus::NoShow)) {
                throw InvalidTransition::for($locked, $from, BookingStatus::NoShow);
            }

            $locked->status = BookingStatus::NoShow;
            $locked->save();

            $locked->load(['slot', 'hosts', 'bookedFor', 'bookedBy']);

            DB::afterCommit(fn () => event(new BookingMarkedNoShow($locked)));

            return $locked;
        });
    }
}
