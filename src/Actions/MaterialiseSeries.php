<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Events\SeriesMaterialised;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesWindow;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;

/**
 * Lay a rule down as ordinary availabilities, from today to the date asked for.
 *
 * Idempotent by construction: an occurrence is keyed
 * `(series_id, occurs_on, window_index)` and a key that already has a row is
 * skipped, whatever state that row is in. So a second run creates nothing, a
 * booked day is never remade, a day somebody detached keeps their edit, and a
 * day left standing by `RegenerateSeries` because it carries a live booking is
 * not duplicated underneath it. Dates before today are never reached at all.
 *
 * The windows it places are wall clock, which is what `Support\SeriesClock`
 * is for — the D10 exception, documented there. Everything written out is a
 * UTC instant.
 */
final class MaterialiseSeries
{
    /**
     * @return int occurrences created by this run
     */
    public function __invoke(Series $series, CarbonImmutable $through): int
    {
        return DB::transaction(function () use ($series, $through): int {
            // Decided from the locked row: a rival edit, pause or sweep queues
            // behind this one instead of laying a second copy of a date down.
            $locked = Dibs::lock($series);

            if (! $locked instanceof Series) {
                throw (new ModelNotFoundException)->setModel($series::class, [$series->getKey()]);
            }

            // A paused series offers nothing and an ended one is over; both may
            // still be edited, and regeneration on either simply removes what
            // no longer fits. Resuming is what materialises again.
            if ($locked->status !== SeriesStatus::Active) {
                return 0;
            }

            $locked->load(['windows', 'hosts']);

            $dates = $locked->occurrenceDates(SeriesClock::today($locked->timezone), $through);

            if ($dates === []) {
                return 0;
            }

            $blocks = $this->blocksByWeekday($locked);
            $taken = $this->existingKeys($locked, $dates);

            /** @var Collection<int, Availability> $created */
            $created = new Collection;

            foreach ($dates as $date) {
                foreach ($blocks[$date->dayOfWeek] ?? [] as $index => $window) {
                    if (isset($taken[$date->format('Y-m-d').'#'.$index])) {
                        continue;
                    }

                    $created->push($this->occurrence($locked, $date, $index, $window));
                }
            }

            if ($created->isNotEmpty()) {
                DB::afterCommit(fn () => event(new SeriesMaterialised($locked, $created)));
            }

            return $created->count();
        });
    }

    /**
     * One occurrence: the availability, its own copy of the series' pool, and
     * its slots. Published through the ordinary action, so a series-made day is
     * the same kind of thing as a day opened by hand and every later behaviour
     * — booking, offers, geometry edits, deletion — still applies to it.
     */
    private function occurrence(Series $series, CarbonImmutable $date, int $index, SeriesWindow $window): Availability
    {
        $availability = Dibs::query(Availability::class)->create([
            'context_type' => $series->context_type,
            'context_id' => $series->context_id,
            'name' => $series->title,
            'location' => $series->location,
            'starts_at' => SeriesClock::instantOn($date, $window->starts_at_minutes, $series->timezone),
            'ends_at' => SeriesClock::instantOn($date, $window->ends_at_minutes, $series->timezone),
            'slot_duration_minutes' => $series->slot_duration_minutes,
            'slot_padding_minutes' => $series->slot_padding_minutes,
            'min_notice_minutes' => $series->min_notice_minutes,
            'max_horizon_days' => $series->max_horizon_days,
            'status' => AvailabilityStatus::Draft,
            'meta' => $series->meta,
            'series_id' => $series->getKey(),
            'occurs_on' => $date->format('Y-m-d'),
            'window_index' => $index,
            'rule_version' => $series->rule_version,
        ]);

        foreach ($series->hosts as $host) {
            $availability->hosts()->create([
                'host_type' => $host->host_type,
                'host_id' => $host->host_id,
                'role' => $host->role,
            ]);
        }

        return (new PublishAvailability)($availability);
    }

    /**
     * The series' windows grouped by weekday and put in clock order, so a
     * block's index is the position it occupies in that day — stable across
     * runs, and the thing the occurrence key is built on.
     *
     * @return array<int, list<SeriesWindow>>
     */
    private function blocksByWeekday(Series $series): array
    {
        $blocks = [];

        foreach ($series->windows->sortBy('starts_at_minutes') as $window) {
            $blocks[$window->weekday][] = $window;
        }

        return $blocks;
    }

    /**
     * The `date#index` keys this series already has rows for, in one query.
     *
     * @param  list<CarbonImmutable>  $dates
     * @return array<string, true>
     */
    private function existingKeys(Series $series, array $dates): array
    {
        $occurrences = Dibs::query(Availability::class)
            ->where('series_id', $series->getKey())
            ->whereIn('occurs_on', array_map(
                static fn (CarbonImmutable $date): string => $date->format('Y-m-d'),
                $dates,
            ))
            ->get(['occurs_on', 'window_index']);

        $taken = [];

        foreach ($occurrences as $occurrence) {
            $taken[$occurrence->occurs_on?->format('Y-m-d').'#'.$occurrence->window_index] = true;
        }

        return $taken;
    }
}
