<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Dibs\Concerns\HasContext;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\SeriesFactory;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * A repeating rule: which weekdays, which hours of them, how often, between
 * which dates. Materialisation turns it into ordinary availabilities, which is
 * where every other behaviour in this package still meets it.
 *
 * The dates this model computes are calendar dates in the series' own
 * timezone. They carry no instant and are never compared against `now()`;
 * `MaterialiseSeries` is the one place a date becomes a UTC instant.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string|null $context_type
 * @property string|null $context_id
 * @property string $title
 * @property string $timezone
 * @property Cadence $cadence
 * @property list<int> $ordinals
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property int $slot_duration_minutes
 * @property int $slot_padding_minutes
 * @property int|null $min_notice_minutes
 * @property int|null $max_horizon_days
 * @property string|null $location
 * @property SeriesStatus $status
 * @property int $rule_version
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EloquentCollection<int, SeriesWindow> $windows
 * @property-read EloquentCollection<int, SeriesHost> $hosts
 * @property-read EloquentCollection<int, Availability> $occurrences
 */
class Series extends Model
{
    use HasContext;

    /** @use HasFactory<SeriesFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'active',
        'cadence' => 'weekly',
        'ordinals' => '[]',
        'slot_padding_minutes' => 0,
        'rule_version' => 1,
        'meta' => '{}',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('series');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cadence' => Cadence::class,
            'status' => SeriesStatus::class,
            'ordinals' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'slot_duration_minutes' => 'integer',
            'slot_padding_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'max_horizon_days' => 'integer',
            'rule_version' => 'integer',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): SeriesFactory
    {
        return SeriesFactory::new();
    }

    /**
     * The stretches of hours this series opens, as minutes from local midnight.
     *
     * @return HasMany<SeriesWindow, $this>
     */
    public function windows(): HasMany
    {
        return $this->hasMany(Dibs::model(SeriesWindow::class), 'series_id');
    }

    /**
     * The pool every occurrence is given a copy of at materialisation.
     *
     * @return HasMany<SeriesHost, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(Dibs::model(SeriesHost::class), 'series_id');
    }

    /**
     * The availabilities materialised from this rule, one per window per date.
     *
     * @return HasMany<Availability, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Dibs::model(Availability::class), 'series_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), SeriesStatus::Active->value);
    }

    /**
     * Does this rule open times on that local date?
     *
     * A date qualifies when the series has at least one window on its weekday,
     * the date is inside `[starts_on, ends_on]`, and the cadence admits it.
     */
    public function occursOn(CarbonInterface $localDate): bool
    {
        $date = $this->day($localDate);

        if ($date->lessThan($this->day($this->starts_on))) {
            return false;
        }

        if ($this->ends_on instanceof CarbonInterface && $date->greaterThan($this->day($this->ends_on))) {
            return false;
        }

        if (! $this->opensOn($date->dayOfWeek)) {
            return false;
        }

        return match ($this->cadence) {
            Cadence::Weekly => true,
            Cadence::Fortnightly => $this->weekIndex($date) % 2 === 0,
            Cadence::Once => $this->weekIndex($date) === 0,
            Cadence::MonthlyOrdinal => $this->matchesOrdinal($date),
        };
    }

    /**
     * Every date this rule opens times on, between two local dates inclusive.
     * `starts_on` and `ends_on` bound the answer whatever the caller asks for.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrenceDates(CarbonInterface $from, CarbonInterface $through): array
    {
        $cursor = $this->day($from)->max($this->day($this->starts_on));
        $last = $this->day($through);

        if ($this->ends_on instanceof CarbonInterface) {
            $last = $last->min($this->day($this->ends_on));
        }

        $dates = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            if ($this->occursOn($cursor)) {
                $dates[] = $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * The Sunday-based week the date falls in, counted from the week containing
     * `starts_on` — 0 for the start week, 1 for the next, and so on. Both sides
     * are plain UTC midnights, so the subtraction is exact whole days.
     */
    private function weekIndex(CarbonImmutable $date): int
    {
        $anchor = $this->day($this->starts_on)->startOfWeek(CarbonInterface::SUNDAY);
        $week = $date->startOfWeek(CarbonInterface::SUNDAY);

        return intdiv($week->getTimestamp() - $anchor->getTimestamp(), 604800);
    }

    /**
     * The date is the n-th of its weekday in its month for some listed ordinal;
     * -1 asks for the last one, whether that is the fourth or the fifth.
     */
    private function matchesOrdinal(CarbonImmutable $date): bool
    {
        $ordinals = array_map(intval(...), $this->ordinals);
        $nth = intdiv($date->day - 1, 7) + 1;

        if (in_array($nth, $ordinals, true)) {
            return true;
        }

        return in_array(-1, $ordinals, true) && $date->day + 7 > $date->daysInMonth;
    }

    private function opensOn(int $weekday): bool
    {
        return $this->windows->contains(
            static fn (SeriesWindow $window): bool => $window->weekday === $weekday,
        );
    }

    /**
     * A calendar date with no instant behind it — `Support\SeriesClock::date()`,
     * which is where every timezone call in this package lives (D10).
     */
    private function day(CarbonInterface $date): CarbonImmutable
    {
        return SeriesClock::date($date);
    }
}
