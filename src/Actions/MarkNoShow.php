<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingMarkedNoShow;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;

/**
 * Record that nobody turned up. A post-hoc judgment: it may be swapped with a
 * completion later, and it never touches the slot.
 */
final class MarkNoShow
{
    public function __invoke(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $booking->refresh();

            $from = $booking->status;

            if (! $from->canTransitionTo(BookingStatus::NoShow)) {
                throw InvalidTransition::for($booking, $from, BookingStatus::NoShow);
            }

            $booking->status = BookingStatus::NoShow;
            $booking->save();

            $booking->load(['slot', 'hosts', 'bookedFor', 'bookedBy']);

            DB::afterCommit(fn () => event(new BookingMarkedNoShow($booking)));

            return $booking;
        });
    }
}
