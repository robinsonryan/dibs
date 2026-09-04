<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use RobinsonRyan\Dibs\Data\UnavailabilitySpec;
use RobinsonRyan\Dibs\Models\Unavailability;

/**
 * The write `CreateUnavailability` and `UpdateUnavailability` both make: an
 * away's windows, replaced wholesale from a spec — so an away edited from
 * standing back to one-off leaves none behind (`SyncSeriesRule` replaces a
 * series' rule for the same reason).
 *
 * Not an action: it takes no lock and fires no event, and is only ever called
 * from inside one of the two that do.
 *
 * @internal
 */
final class SyncAwayWindows
{
    public static function windows(Unavailability $away, UnavailabilitySpec $spec): void
    {
        $away->windows()->delete();

        foreach ($spec->windows as $window) {
            $away->windows()->create([
                'weekday' => $window->weekday,
                'starts_at_minutes' => $window->startsAtMinutes,
                'ends_at_minutes' => $window->endsAtMinutes,
            ]);
        }

        $away->unsetRelation('windows');
    }
}
