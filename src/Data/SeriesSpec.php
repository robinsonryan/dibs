<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;

/**
 * A whole series rule, as the caller means it: what it is called, whose it is,
 * which clock it keeps, how it repeats, which hours of which weekdays, and who
 * conducts. `CreateSeries` and `UpdateSeries` take nothing else.
 *
 * `ensureValid()` enforces only what is true of *any* consumer's series — the
 * geometry has to be coherent and the rule has to name a pool. Rules that
 * belong to a domain (church hours are 6 am to 10 pm, gaps round to the half
 * hour, the title is unique in a ward) stay with the consumer, which is also
 * the only place that can phrase the refusal for a person to read.
 */
final readonly class SeriesSpec
{
    /**
     * The ordinals a monthly rule may name: the first through fifth of its
     * weekday in the month, and -1 for the last, whether that is the fourth or
     * the fifth.
     */
    public const ORDINALS = [1, 2, 3, 4, 5, -1];

    /**
     * @param  list<int>  $ordinals  1…5 and -1 for the last; monthly-ordinal only
     * @param  list<WindowSpec>  $windows
     * @param  list<HostAssignment>  $hosts
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $title,
        public Model $context,
        public string $timezone,
        public Cadence $cadence,
        public array $ordinals,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public int $slotDurationMinutes,
        public int $slotPaddingMinutes,
        public ?int $minNoticeMinutes,
        public ?int $maxHorizonDays,
        public ?string $location,
        public array $windows,
        public array $hosts,
        public array $meta = [],
    ) {}

    /**
     * @throws InvalidSeries
     */
    public function ensureValid(): void
    {
        if ($this->windows === []) {
            throw InvalidSeries::because(InvalidSeries::WINDOWS_REQUIRED, 'A series needs at least one window.');
        }

        if ($this->hosts === []) {
            throw InvalidSeries::because(InvalidSeries::HOSTS_REQUIRED, 'A series needs at least one host in its pool.');
        }

        if ($this->endsOn instanceof CarbonImmutable && $this->endsOn->lessThanOrEqualTo($this->startsOn)) {
            throw InvalidSeries::because(InvalidSeries::ENDS_BEFORE_STARTS, 'A series must end after it starts.');
        }

        $this->ensureTimezoneExists();
        $this->ensureOrdinalsMatchCadence();
        $this->ensureWindowsAreCoherent();
    }

    /**
     * The ordinals as they should be stored: each one named once, in order.
     * `[1, 1, -1]` and `[-1, 1]` are the same rule, and a rule that reads twice
     * as long as it is would have `UpdateSeries` calling an edit a rule change
     * when nothing moved.
     *
     * @return list<int>
     */
    public function ordinals(): array
    {
        $unique = array_values(array_unique(array_map(intval(...), $this->ordinals)));

        sort($unique);

        return $unique;
    }

    /**
     * A timezone the runtime actually knows. Unchecked, `Mars/Olympus` was
     * stored happily and surfaced much later as Carbon's own
     * `InvalidTimeZoneException`, thrown inside the materialisation
     * transaction; this refuses before anything is written, in the package's
     * own exception with a machine reason.
     *
     * @throws InvalidSeries
     */
    private function ensureTimezoneExists(): void
    {
        if (! in_array($this->timezone, timezone_identifiers_list(), true)) {
            throw InvalidSeries::because(InvalidSeries::TIMEZONE_INVALID, 'A series must keep a timezone the system knows.');
        }
    }

    /**
     * Ordinals mean something only to the monthly cadence; carrying them on any
     * other is a rule that reads one way and behaves another.
     *
     * @throws InvalidSeries
     */
    private function ensureOrdinalsMatchCadence(): void
    {
        if ($this->cadence->usesOrdinals() && $this->ordinals === []) {
            throw InvalidSeries::because(InvalidSeries::ORDINALS_REQUIRED, 'A monthly series must name at least one ordinal.');
        }

        if (! $this->cadence->usesOrdinals() && $this->ordinals !== []) {
            throw InvalidSeries::because(InvalidSeries::ORDINALS_FORBIDDEN, 'Only a monthly series may carry ordinals.');
        }

        foreach ($this->ordinals as $ordinal) {
            if (! in_array((int) $ordinal, self::ORDINALS, true)) {
                throw InvalidSeries::because(InvalidSeries::ORDINALS_BOUNDS, 'An ordinal must be 1 to 5, or -1 for the last.');
            }
        }
    }

    /**
     * Each window inside its own day, and the windows sharing a weekday far
     * enough apart that a whole appointment — its length plus the gap that
     * follows it — fits between them. That space *is* the break: two blocks
     * closer than one appointment are one block with a hole in it.
     *
     * @throws InvalidSeries
     */
    private function ensureWindowsAreCoherent(): void
    {
        $byWeekday = [];

        foreach ($this->windows as $window) {
            if ($window->weekday < 0 || $window->weekday > 6) {
                throw InvalidSeries::because(InvalidSeries::WINDOWS_BOUNDS, 'A window must fall on a weekday from 0 (Sunday) to 6.');
            }

            if ($window->startsAtMinutes < 0 || $window->endsAtMinutes > 1440) {
                throw InvalidSeries::because(InvalidSeries::WINDOWS_BOUNDS, 'A window must fall inside its own day.');
            }

            if ($window->endsAtMinutes <= $window->startsAtMinutes) {
                throw InvalidSeries::because(InvalidSeries::WINDOWS_BOUNDS, 'A window must end after it starts.');
            }

            $byWeekday[$window->weekday][] = $window;
        }

        $clearance = $this->slotDurationMinutes + $this->slotPaddingMinutes;

        foreach ($byWeekday as $windows) {
            usort($windows, static fn (WindowSpec $a, WindowSpec $b): int => $a->startsAtMinutes <=> $b->startsAtMinutes);

            $previous = null;

            foreach ($windows as $window) {
                if ($previous instanceof WindowSpec) {
                    if ($window->startsAtMinutes < $previous->endsAtMinutes) {
                        throw InvalidSeries::because(InvalidSeries::WINDOWS_OVERLAP, 'Two windows on one weekday overlap.');
                    }

                    if ($window->startsAtMinutes - $previous->endsAtMinutes < $clearance) {
                        throw InvalidSeries::because(InvalidSeries::WINDOWS_GAP, 'Two windows on one weekday leave no room for an appointment between them.');
                    }
                }

                $previous = $window;
            }
        }
    }
}
