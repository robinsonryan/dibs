<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;

/**
 * Mark one day as edited by hand, so the rule stops managing it.
 *
 * From here on regeneration passes it by and materialisation will not remake
 * it; the consumer edits it like any other availability. Pausing still retires
 * its unclaimed times, because a paused series offers nothing at all.
 *
 * `FollowSeries` is the way back.
 */
final class DetachOccurrence
{
    public function __invoke(Availability $occurrence): Availability
    {
        return DB::transaction(function () use ($occurrence): Availability {
            $locked = Dibs::lock($occurrence);

            if (! $locked instanceof Availability) {
                throw (new ModelNotFoundException)->setModel($occurrence::class, [$occurrence->getKey()]);
            }

            if ($locked->series_id === null) {
                throw InvalidSeries::because(
                    InvalidSeries::OCCURRENCE_NOT_IN_SERIES,
                    'Only an availability made by a series can be detached from one.',
                );
            }

            if ($locked->isDetached()) {
                return $locked;
            }

            $locked->detached_at = SeriesClock::now();
            $locked->save();

            return $locked;
        });
    }
}
