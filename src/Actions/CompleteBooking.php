<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingCompleted;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;

/**
 * Record that a booking was kept. A post-hoc judgment: it may be swapped with
 * a no-show later, and it never touches the slot.
 */
final class CompleteBooking
{
    public function __invoke(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $booking->refresh();

            $from = $booking->status;

            if (! $from->canTransitionTo(BookingStatus::Completed)) {
                throw InvalidTransition::for($booking, $from, BookingStatus::Completed);
            }

            $booking->status = BookingStatus::Completed;
            $booking->save();

            $booking->load(['slot', 'hosts', 'bookedFor', 'bookedBy']);

            DB::afterCommit(fn () => event(new BookingCompleted($booking)));

            return $booking;
        });
    }
}
