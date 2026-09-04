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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\UnavailabilityFactory;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * Time its scope is not to be offered: a person who cannot take appointments
 * that evening, or a context whose whole calendar is closed for it.
 *
 * It is a **read-time filter, never an edit** — nothing it covers is deleted,
 * repriced or rescheduled. Availabilities, slots and series stand exactly as
 * they were; the busy definition (`Support\OverlapCheck`) simply stops counting
 * the scope as free while an away covers the time, and puts it back the moment
 * the away goes.
 *
 * Two shapes. A **one-off** is a plain instant span, so D10 holds for it
 * unchanged. A **standing** away is weekday windows as minutes from local
 * midnight, read on its own `timezone` through `Support\SeriesClock` exactly as
 * a series' windows are — six o'clock stays six o'clock across a daylight-saving
 * change, which no stored offset can manage.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $scope_type
 * @property string $scope_id
 * @property UnavailabilityKind $kind
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $timezone
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $label
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EloquentCollection<int, UnavailabilityWindow> $windows
 */
class Unavailability extends Model
{
    /** @use HasFactory<UnavailabilityFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'kind' => 'once',
        'meta' => '{}',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('unavailabilities');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => UnavailabilityKind::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): UnavailabilityFactory
    {
        return UnavailabilityFactory::new();
    }

    /**
     * Whose away this is: a host, or a context whose whole calendar it closes.
     *
     * @return MorphTo<Model, $this>
     */
    public function scope(): MorphTo
    {
        return $this->morphTo('scope');
    }

    /**
     * The stretches of hours a standing away keeps, as minutes from local
     * midnight. Empty on a one-off.
     *
     * @return HasMany<UnavailabilityWindow, $this>
     */
    public function windows(): HasMany
    {
        return $this->hasMany(Dibs::model(UnavailabilityWindow::class), 'unavailability_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForScope(Builder $query, Model $scope): Builder
    {
        return $query
            ->where($this->qualifyColumn('scope_type'), $scope->getMorphClass())
            ->where($this->qualifyColumn('scope_id'), (string) $scope->getKey());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnce(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('kind'), UnavailabilityKind::Once->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWeekly(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('kind'), UnavailabilityKind::Weekly->value);
    }

    /**
     * Does this away take any part of `[$start, $end)` out?
     *
     * Half-open, like every other overlap in this package: an away that ends
     * exactly when the time opens does not cover it.
     */
    public function covers(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->intervalsBetween($start, $end) !== [];
    }

    /**
     * The concrete UTC spans this away puts inside `[$from, $to)` — one for a
     * one-off, one per window per local date for a standing one.
     *
     * `covers()` is this asked as a yes/no, and the read paths use it to turn a
     * wall-clock rule into instants **once per read** rather than once per slot:
     * SQL cannot call PHP, so the conversion has to happen before the filter,
     * and the values it produces are then compared against slot rows in SQL
     * (`Slot::scopeBookable`).
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function intervalsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = Slot::instant($from);
        $end = Slot::instant($to);

        if ($end->lessThanOrEqualTo($start)) {
            return [];
        }

        return $this->kind === UnavailabilityKind::Once
            ? $this->onceInterval($start, $end)
            : $this->weeklyIntervals($start, $end);
    }

    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function onceInterval(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $opens = $this->starts_at;
        $closes = $this->ends_at;

        if (! $opens instanceof CarbonImmutable || ! $closes instanceof CarbonImmutable) {
            return [];
        }

        return $opens->lessThan($end) && $closes->greaterThan($start)
            ? [['starts_at' => $opens, 'ends_at' => $closes]]
            : [];
    }

    /**
     * Every local date `[$start, $end)` touches on this away's own clock, and
     * the windows that fall on each of their weekdays, placed on that date
     * through `SeriesClock` — the same conversion materialisation makes, so an
     * away and a series read a wall clock exactly one way.
     *
     * A window a spring-forward swallows converts to a zero-length or inverted
     * span on that one date and is skipped there, which is the R79 rule: an
     * hour that does not exist takes nothing out.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function weeklyIntervals(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $windows = $this->windows;

        if ($windows->isEmpty()) {
            return [];
        }

        $cursor = SeriesClock::localDate($start, $this->timezone);
        $last = SeriesClock::localDate($end, $this->timezone);
        $intervals = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            foreach ($this->windowsOn($cursor) as $window) {
                $opens = SeriesClock::instantOn($cursor, $window->starts_at_minutes, $this->timezone);
                $closes = SeriesClock::instantOn($cursor, $window->ends_at_minutes, $this->timezone);

                if ($closes->greaterThan($opens) && $opens->lessThan($end) && $closes->greaterThan($start)) {
                    $intervals[] = ['starts_at' => $opens, 'ends_at' => $closes];
                }
            }

            $cursor = $cursor->addDay();
        }

        return $intervals;
    }

    /**
     * The windows this away keeps on that local date — none at all before it
     * starts or after it ends, both read on its own calendar.
     *
     * @return array<int, UnavailabilityWindow>
     */
    private function windowsOn(CarbonImmutable $date): array
    {
        if ($this->starts_on instanceof CarbonImmutable && $date->lessThan(SeriesClock::date($this->starts_on))) {
            return [];
        }

        if ($this->ends_on instanceof CarbonImmutable && $date->greaterThan(SeriesClock::date($this->ends_on))) {
            return [];
        }

        return $this->windows
            ->filter(static fn (UnavailabilityWindow $window): bool => $window->weekday === $date->dayOfWeek)
            ->values()
            ->all();
    }
}
