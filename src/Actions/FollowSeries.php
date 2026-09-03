<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * Put a hand-edited day back under its rule, and bring it into line.
 *
 * The day is un-detached and marked as being on an older version of the rule,
 * which is precisely what `RegenerateSeries` looks for — so the one code path
 * that remakes days remakes this one, with the same three refusals (it will not
 * touch the past, and it leaves a day carrying a live booking for the consumer
 * to settle first).
 *
 * Returns whatever now stands on that date and block: the remade day, or the
 * original where a live booking kept it in place.
 */
final class FollowSeries
{
    public function __invoke(Availability $occurrence): Availability
    {
        return DB::transaction(function () use ($occurrence): Availability {
            $locked = Dibs::lock($occurrence);

            if (! $locked instanceof Availability) {
                throw (new ModelNotFoundException)->setModel($occurrence::class, [$occurrence->getKey()]);
            }

            $series = $locked->series;

            if (! $series instanceof Series) {
                throw InvalidSeries::because(
                    InvalidSeries::OCCURRENCE_NOT_IN_SERIES,
                    'Only an availability made by a series can follow one.',
                );
            }

            $date = $locked->occurs_on;
            $index = $locked->window_index;

            $locked->forceFill([
                'detached_at' => null,
                // Deliberately stale, so regeneration picks this day up and
                // nothing else has to know it was ever detached.
                'rule_version' => max(0, $series->rule_version - 1),
            ])->save();

            (new RegenerateSeries)($series);

            $current = Dibs::query(Availability::class)
                ->where('series_id', $series->getKey())
                ->where('occurs_on', $date?->format('Y-m-d'))
                ->where('window_index', $index)
                ->first();

            return $current instanceof Availability ? $current : $locked;
        });
    }
}
