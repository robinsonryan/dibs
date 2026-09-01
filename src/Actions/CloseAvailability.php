<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Events\AvailabilityClosed;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;

/**
 * Close a published availability (§5.1). Slot rows and their bookings are left
 * exactly as they are; the open ones simply stop satisfying `Slot::bookable()`.
 */
final class CloseAvailability
{
    public function __invoke(Availability $availability): Availability
    {
        return DB::transaction(function () use ($availability): Availability {
            $availability->refresh();

            $from = $availability->status;

            if (! $from->canTransitionTo(AvailabilityStatus::Closed)) {
                throw InvalidTransition::for($availability, $from, AvailabilityStatus::Closed);
            }

            $availability->status = AvailabilityStatus::Closed;
            $availability->save();

            $availability->load(['slots', 'hosts']);

            DB::afterCommit(fn () => event(new AvailabilityClosed($availability)));

            return $availability;
        });
    }
}
