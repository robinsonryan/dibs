<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * Delete an availability, cascading its slots and host pool. Refused while any
 * slot is held or carries a booking row — bookings are history (D3, D6).
 */
final class DeleteAvailability
{
    public function __invoke(Availability $availability): void
    {
        DB::transaction(function () use ($availability): void {
            // Every refusal below is decided from these locked rows, so an offer
            // taking a hold or a booking landing mid-check queues behind the
            // delete instead of slipping through it.
            $slots = $availability->slots()->lockForUpdate()->get();

            if ($slots->contains(fn (Slot $slot): bool => $slot->status === SlotStatus::Held)) {
                throw DeletionRefused::for($availability, 'one of its slots is held.');
            }

            if ($availability->slots()->has('bookings')->exists()) {
                throw DeletionRefused::for($availability, 'one of its slots has bookings.');
            }

            $availability->delete();
        });
    }
}
