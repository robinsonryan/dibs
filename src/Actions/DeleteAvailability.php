<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Models\Availability;

/**
 * Delete an availability, cascading its slots and host pool. Refused while any
 * slot is held or carries a booking row — bookings are history (D3, D6).
 */
final class DeleteAvailability
{
    public function __invoke(Availability $availability): void
    {
        DB::transaction(function () use ($availability): void {
            if ($availability->slots()->where('status', SlotStatus::Held->value)->count() > 0) {
                throw DeletionRefused::for($availability, 'one of its slots is held.');
            }

            if ($availability->slots()->has('bookings')->count() > 0) {
                throw DeletionRefused::for($availability, 'one of its slots has bookings.');
            }

            $availability->delete();
        });
    }
}
