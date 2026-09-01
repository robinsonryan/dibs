<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Delete an availability, cascading its slots and host pool. Refused while any
 * slot is held or carries a booking row — bookings are history (D3, D6).
 */
final class DeleteAvailability
{
    public function __invoke(Availability $availability): void
    {
        DB::transaction(function () use ($availability): void {
            // Re-read through the class-map so the checks and the delete all run
            // on the connection this transaction opened on, never on whatever
            // connection hydrated the model the caller handed in (R42).
            $locked = Dibs::lock($availability);

            // Already gone: nothing left to refuse or cascade.
            if (! $locked instanceof Availability) {
                return;
            }

            // Every refusal below is decided from these locked rows, so an offer
            // taking a hold or a booking landing mid-check queues behind the
            // delete instead of slipping through it.
            $slots = Dibs::query(Slot::class)
                ->where('availability_id', $locked->getKey())
                ->lockForUpdate()
                ->get();

            if ($slots->contains(fn (Slot $slot): bool => $slot->status === SlotStatus::Held)) {
                throw DeletionRefused::for($availability, 'one of its slots is held.');
            }

            $hasBookings = Dibs::query(Slot::class)
                ->where('availability_id', $locked->getKey())
                ->has('bookings')
                ->exists();

            if ($hasBookings) {
                throw DeletionRefused::for($availability, 'one of its slots has bookings.');
            }

            $locked->delete();
        });
    }
}
