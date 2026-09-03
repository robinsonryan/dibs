<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Models\Series;

/**
 * The two writes `CreateSeries` and `UpdateSeries` both make: a series' windows
 * and its pool, replaced wholesale from a spec. Replace rather than diff for
 * the same reason regeneration never diffs — `rule_version` already says
 * "something about the rule moved", and a diff would be a second, subtler
 * answer to a question already answered.
 *
 * Not an action: it takes no lock and fires no event, and is only ever called
 * from inside one of the two that do.
 *
 * @internal
 */
final class SyncSeriesRule
{
    public static function windows(Series $series, SeriesSpec $spec): void
    {
        $series->windows()->delete();

        foreach ($spec->windows as $window) {
            $series->windows()->create([
                'weekday' => $window->weekday,
                'starts_at_minutes' => $window->startsAtMinutes,
                'ends_at_minutes' => $window->endsAtMinutes,
            ]);
        }

        $series->unsetRelation('windows');
    }

    public static function hosts(Series $series, SeriesSpec $spec): void
    {
        $series->hosts()->delete();

        foreach ($spec->hosts as $host) {
            $series->hosts()->create([
                'host_type' => $host->host->getMorphClass(),
                'host_id' => (string) $host->host->getKey(),
                'role' => $host->role,
            ]);
        }

        $series->unsetRelation('hosts');
    }
}
