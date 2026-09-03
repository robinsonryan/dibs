<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Events\SeriesDeleted;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Delete a rule outright, with its blocks, its pool and its days.
 *
 * Refused the moment any of its days has ever carried a booking — cancelled and
 * completed ones included, because those are the record of what happened and
 * deleting the rule would take them with it (D3, D6). A series that has been
 * used is ended or paused, never deleted.
 *
 * "Any of its days" includes the ones it no longer points at. A day whose
 * bookings were all spent when the rule moved was *released* — cut loose from
 * the series so the date was free — and counting only current occurrences would
 * have let a rule that had plainly been used be deleted the moment somebody
 * cancelled and the leader edited the hours. Release leaves
 * `meta.released_from_series` behind precisely so this question can still find
 * them.
 *
 * Each day goes through `DeleteAvailability`, so a day with a held slot — an
 * offer somebody is still deciding on — refuses in the same words it always has.
 */
final class DeleteSeries
{
    public function __invoke(Series $series): void
    {
        DB::transaction(function () use ($series): void {
            $locked = Dibs::lock($series);

            // Already gone: nothing left to refuse or cascade.
            if (! $locked instanceof Series) {
                return;
            }

            $occurrences = Dibs::query(Availability::class)
                ->where('series_id', $locked->getKey())
                ->lockForUpdate()
                ->get();

            $released = Dibs::query(Availability::class)
                ->where('meta->released_from_series', (string) $locked->getKey())
                ->pluck('id')
                ->all();

            $booked = Dibs::query(Slot::class)
                ->whereIn('availability_id', array_merge($occurrences->modelKeys(), $released))
                ->has('bookings')
                ->exists();

            if ($booked) {
                throw DeletionRefused::for($locked, 'one of its times has bookings.');
            }

            foreach ($occurrences as $occurrence) {
                (new DeleteAvailability)($occurrence);
            }

            $locked->delete();

            DB::afterCommit(fn () => event(new SeriesDeleted($locked)));
        });
    }
}
