<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one place this package reads a wall clock, and the single sanctioned
 * exception to "UTC instants only" (spec D10).
 *
 * A series' windows are minutes from local midnight, so nothing but a date plus
 * the series' timezone can say which instant "6 pm" is on a given day — and
 * 6 pm has to stay 6 pm across a daylight-saving change, which no stored offset
 * can manage. Everything that comes back out of here is a UTC instant, so D10
 * holds for every row written and every comparison made downstream.
 *
 * `MaterialiseSeries` uses it to place occurrences; `FindSeriesConflicts` uses
 * it to ask whether an existing booking would still fall inside a proposed
 * rule's window, which is the same conversion asked backwards; `Series` uses
 * `date()` to read a calendar date without an instant behind it.
 */
final class SeriesClock
{
    /**
     * The instant a local wall clock lands on, on that date, in that zone.
     *
     * The date is shifted rather than converted — the calendar date the rule
     * names is kept and the clock is then set on it — so whatever offset the
     * date was carrying when it was computed never leaks in. An end of 1440
     * minutes is midnight the following morning, which is what a window running
     * to the end of the day means.
     */
    public static function instantOn(CarbonImmutable $date, int $minutes, string $timezone): CarbonImmutable
    {
        return $date
            ->shiftTimezone($timezone)
            ->setTime(intdiv($minutes, 60), $minutes % 60)
            ->utc();
    }

    /**
     * A calendar date with no instant behind it: the same year, month and day at
     * UTC midnight, whatever zone the caller's Carbon was carrying.
     *
     * Shifted, not converted — the wall clock the caller wrote is kept and
     * re-read as UTC, so 6 pm in Denver is still the 8th and not the 9th. UTC
     * has no daylight saving, so midnights are exactly 86400s apart and the
     * week arithmetic `Series` does on top of this is whole-day integer maths.
     * Nothing this returns is ever compared against a stored `timestamptz`.
     *
     * It lives here, and not on `Series`, because this class is D10's single
     * sanctioned exception: one grep for a timezone call in `src/`, one hit.
     */
    public static function date(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->shiftTimezone('UTC')->startOfDay();
    }

    /**
     * The clock, in UTC — the stamp `detached_at` and its like are written at.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /**
     * The local date the series is standing on now. Materialisation only ever
     * runs forwards, and "today" is the series' today, not the server's.
     */
    public static function today(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfDay();
    }
}
