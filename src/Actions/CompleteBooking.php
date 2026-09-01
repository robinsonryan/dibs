<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingCompleted;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Record that a booking was kept. A post-hoc judgment: it may be swapped with
 * a no-show later, and it never touches the slot.
 */
final class CompleteBooking
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

            if (! $from->canTransitionTo(BookingStatus::Completed)) {
                throw InvalidTransition::for($locked, $from, BookingStatus::Completed);
            }

            $locked->status = BookingStatus::Completed;
            $locked->save();

            $locked->load(['slot', 'hosts', 'bookedFor', 'bookedBy']);

            DB::afterCommit(fn () => event(new BookingCompleted($locked)));

            return $locked;
        });
    }
}
