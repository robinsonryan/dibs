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
 *
 * One window can fail to become a time at all: an hour that a daylight-saving
 * spring-forward swallows (02:00–03:00 on the changeover date in a zone that
 * jumps from 02:00 to 03:00) converts to a zero-length or inverted instant on
 * **that one date**. That date's occurrence for that block is skipped, with no
 * exception — the hour genuinely does not exist there, and the alternative was
 * an `InvalidGeometry` that rolled back the whole run, taking every other date
 * and every other block of the series with it and failing again every night.
 * Every other date the rule names is laid down as normal.
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

            $blocks = $locked->blocks();
            $taken = $this->existingKeys($locked, $dates);

            /** @var Collection<int, Availability> $created */
            $created = new Collection;

            foreach ($dates as $date) {
                foreach ($blocks[$date->dayOfWeek] ?? [] as $index => $window) {
                    if (isset($taken[$date->format('Y-m-d').'#'.$index])) {
                        continue;
                    }

                    $opens = SeriesClock::instantOn($date, $window->starts_at_minutes, $locked->timezone);
                    $closes = SeriesClock::instantOn($date, $window->ends_at_minutes, $locked->timezone);

                    // The hour does not exist on this date (a spring-forward
                    // gap). Skipped here rather than refused downstream.
                    if ($closes->lessThanOrEqualTo($opens)) {
                        continue;
                    }

                    $created->push($this->occurrence($locked, $date, $index, $opens, $closes));
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
    private function occurrence(Series $series, CarbonImmutable $date, int $index, CarbonImmutable $opens, CarbonImmutable $closes): Availability
    {
        $availability = Dibs::query(Availability::class)->create([
            'context_type' => $series->context_type,
            'context_id' => $series->context_id,
            'name' => $series->title,
            'location' => $series->location,
            'starts_at' => $opens,
            'ends_at' => $closes,
            'slot_duration_minutes' => $series->slot_duration_minutes,
            'slot_padding_minutes' => $series->slot_padding_minutes,
            'min_notice_minutes' => $series->min_notice_minutes,
            'max_horizon_days' => $series->max_horizon_days,
            'status' => AvailabilityStatus::Draft,
            // A time laid down by a rule is measured by its pool: the rule says
            // who fulfils the day, and how many appointments the day holds
            // follows from how many of them are free (D18).
            'capacity_from_pool' => true,
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
