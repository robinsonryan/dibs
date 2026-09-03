<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

/**
 * How a series repeats. Week indices are Sunday-based and counted from the week
 * containing `starts_on`, so "every other week" never drifts off the calendar
 * the way an "every 14 days" rule does.
 *
 * `MonthlyOrdinal` reads the series' `ordinals` (1…5, and -1 for the last):
 * a date qualifies when it is the n-th occurrence of its own weekday in its own
 * month for some n in that list. A month with no fifth Tuesday simply has no
 * occurrence — nothing is shifted onto another date.
 *
 * `Once` is the "does not repeat" case, expressed as a cadence rather than as
 * an absent series: week index 0 only.
 */
enum Cadence: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case MonthlyOrdinal = 'monthly-ordinal';
    case Once = 'once';

    /**
     * Only the monthly cadence reads `ordinals`; every other cadence must carry
     * an empty list, so a stale ordinal can never silently change a rule.
     */
    public function usesOrdinals(): bool
    {
        return $this === self::MonthlyOrdinal;
    }
}
