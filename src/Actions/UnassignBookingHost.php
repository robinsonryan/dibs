<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingHostUnassigned;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Clear a booking's role, leaving it for someone to take (D14). Allowed on a
 * completed or no-show booking, whose record may still be corrected; a
 * cancelled one is frozen.
 */
final class UnassignBookingHost
{
    /**
     * @throws InvalidTransition when the booking is cancelled
     */
    public function __invoke(Booking $booking, string $role = 'host'): Booking
    {
        return DB::transaction(function () use ($booking, $role): Booking {
            $locked = Dibs::lock($booking);

            if (! $locked instanceof Booking) {
                throw (new ModelNotFoundException)->setModel($booking::class, [$booking->getKey()]);
            }

            if ($locked->status === BookingStatus::Cancelled) {
                throw InvalidTransition::for($locked, $locked->status, BookingStatus::Booked);
            }

            $existing = $locked->hosts()->where('role', $role)->with('host')->orderBy('id')->get();

            if ($existing->isEmpty()) {
                $locked->load(['hosts.host']);

                return $locked;
            }

            $locked->hosts()->where('role', $role)->delete();

            $locked->load(['hosts.host']);

            foreach ($existing as $assignment) {
                $previous = $assignment->host;

                // A host the consumer has since deleted is still removed, but
                // there is no model to announce it with (B35).
                if (! $previous instanceof Model) {
                    continue;
                }

                DB::afterCommit(fn () => event(new BookingHostUnassigned($locked, $role, $previous)));
            }

            return $locked;
        });
    }
}
