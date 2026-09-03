<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Cut a slot loose from the day it was born on, keeping the appointment.
 *
 * This is how "keep it" is answered when a rule change would otherwise take a
 * booked time with it: the slot becomes adhoc (D4's shape), so the day can be
 * remade or deleted around it, and the booking, its hosts, its time and its
 * place are all untouched. The place is copied down from the day when the slot
 * had none of its own — an adhoc slot is the only record of where it is.
 */
final class ReparentSlotAsAdhoc
{
    public function __invoke(Slot $slot): Slot
    {
        return DB::transaction(function () use ($slot): Slot {
            $locked = Dibs::lock($slot);

            if (! $locked instanceof Slot) {
                throw (new ModelNotFoundException)->setModel($slot::class, [$slot->getKey()]);
            }

            if ($locked->isAdhoc()) {
                return $locked;
            }

            $availability = $locked->availability;

            $locked->forceFill([
                'availability_id' => null,
                'location' => $locked->location ?? ($availability instanceof Availability ? $availability->location : null),
            ])->save();

            return $locked;
        });
    }
}
