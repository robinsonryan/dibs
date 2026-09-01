<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Events\AvailabilityClosed;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Close a published availability (§5.1). Slot rows and their bookings are left
 * exactly as they are; the open ones simply stop satisfying `Slot::bookable()`.
 */
final class CloseAvailability
{
    public function __invoke(Availability $availability): Availability
    {
        return DB::transaction(function () use ($availability): Availability {
            // Decided from the locked row, not the caller's copy.
            $locked = Dibs::lock($availability);

            if (! $locked instanceof Availability) {
                throw (new ModelNotFoundException)->setModel($availability::class, [$availability->getKey()]);
            }

            $from = $locked->status;

            if (! $from->canTransitionTo(AvailabilityStatus::Closed)) {
                throw InvalidTransition::for($locked, $from, AvailabilityStatus::Closed);
            }

            $locked->status = AvailabilityStatus::Closed;
            $locked->save();

            $locked->load(['slots', 'hosts']);

            DB::afterCommit(fn () => event(new AvailabilityClosed($locked)));

            return $locked;
        });
    }
}
