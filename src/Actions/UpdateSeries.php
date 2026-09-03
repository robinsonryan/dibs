<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesHost;
use RobinsonRyan\Dibs\Models\SeriesWindow;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;

/**
 * Change a series, and decide from what changed how much has to be remade.
 *
 * Two kinds of edit. One touches the **rule** — the windows, the cadence and
 * its ordinals, the dates, the length of an appointment or the gap after it,
 * the place, the pool, the clock the series keeps. That bumps `rule_version`
 * and regenerates: every future day that still follows the series is remade
 * from the new rule (`RegenerateSeries` for what it will and will not touch).
 *
 * What an edit may **not** touch is the context. It is stamped on every
 * occurrence and on every copy of the pool, and moving it on the series alone
 * left days in two tenants at once, so `UpdateSeries` refuses a context change
 * outright (`InvalidSeries`, reason `context.immutable`) rather than
 * half-applying it. Moving a rule between tenants means creating it in the new
 * one; the days already made belong to the tenant they were made for.
 *
 * The other touches only what a day *carries* — its name, the consumer's meta,
 * how much notice a booking needs, how far ahead times are offered. Those are
 * copied straight onto the future days that follow the series. No version bump,
 * no regeneration, so nobody's booking is disturbed to rename a set of times.
 */
final class UpdateSeries
{
    public function __invoke(Series $series, SeriesSpec $spec): Series
    {
        $spec->ensureValid();

        return DB::transaction(function () use ($series, $spec): Series {
            $locked = Dibs::lock($series);

            if (! $locked instanceof Series) {
                throw (new ModelNotFoundException)->setModel($series::class, [$series->getKey()]);
            }

            $this->assertContextIsUnchanged($locked, $spec);

            $locked->load(['windows', 'hosts']);

            $ruleChanged = $this->ruleChanged($locked, $spec);

            $locked->fill([
                'title' => $spec->title,
                'timezone' => $spec->timezone,
                'cadence' => $spec->cadence,
                'ordinals' => $spec->ordinals(),
                'starts_on' => $spec->startsOn,
                'ends_on' => $spec->endsOn,
                'slot_duration_minutes' => $spec->slotDurationMinutes,
                'slot_padding_minutes' => $spec->slotPaddingMinutes,
                'min_notice_minutes' => $spec->minNoticeMinutes,
                'max_horizon_days' => $spec->maxHorizonDays,
                'location' => $spec->location,
                'meta' => $spec->meta,
            ]);

            if ($ruleChanged) {
                $locked->rule_version++;
            }

            $locked->save();

            if ($ruleChanged) {
                SyncSeriesRule::windows($locked, $spec);
                SyncSeriesRule::hosts($locked, $spec);

                (new RegenerateSeries)($locked);
            } else {
                $this->restamp($locked);
            }

            $locked->load(['windows', 'hosts']);

            return $locked;
        });
    }

    /**
     * Refused before anything is written: a series' context is on every day it
     * has made and on every pool row copied onto them, and this action rewrites
     * neither, so accepting the change would leave one rule with days in two
     * tenants.
     *
     * @throws InvalidSeries
     */
    private function assertContextIsUnchanged(Series $series, SeriesSpec $spec): void
    {
        $same = $series->context_type === $spec->context->getMorphClass()
            && $series->context_id === (string) $spec->context->getKey();

        if (! $same) {
            throw InvalidSeries::because(
                InvalidSeries::CONTEXT_IMMUTABLE,
                'A series keeps the context it was created in.',
            );
        }
    }

    /**
     * Copy what a day carries onto the future days that still follow the
     * series. Saved one at a time rather than by a mass update, so the model's
     * casts write `meta` as the consumer handed it over.
     */
    private function restamp(Series $series): void
    {
        $today = SeriesClock::today($series->timezone);

        $occurrences = Dibs::query(Availability::class)
            ->where('series_id', $series->getKey())
            ->whereNull('detached_at')
            ->where('occurs_on', '>=', $today->format('Y-m-d'))
            ->get();

        foreach ($occurrences as $occurrence) {
            $occurrence->fill([
                'name' => $series->title,
                'meta' => $series->meta,
                'min_notice_minutes' => $series->min_notice_minutes,
                'max_horizon_days' => $series->max_horizon_days,
            ])->save();
        }
    }

    /**
     * Did anything that decides *which* times exist move? Everything else is
     * carried, not computed, and can be restamped in place.
     */
    private function ruleChanged(Series $series, SeriesSpec $spec): bool
    {
        if ($series->timezone !== $spec->timezone) {
            return true;
        }

        if ($series->cadence !== $spec->cadence) {
            return true;
        }

        if ($this->sorted($series->ordinals) !== $spec->ordinals()) {
            return true;
        }

        if ($series->starts_on->format('Y-m-d') !== $spec->startsOn->format('Y-m-d')) {
            return true;
        }

        if ($series->ends_on?->format('Y-m-d') !== $spec->endsOn?->format('Y-m-d')) {
            return true;
        }

        if ($series->slot_duration_minutes !== $spec->slotDurationMinutes) {
            return true;
        }

        if ($series->slot_padding_minutes !== $spec->slotPaddingMinutes) {
            return true;
        }

        if ($series->location !== $spec->location) {
            return true;
        }

        return $this->windowsChanged($series, $spec) || $this->hostsChanged($series, $spec);
    }

    private function windowsChanged(Series $series, SeriesSpec $spec): bool
    {
        $before = $series->windows
            ->map(static fn (SeriesWindow $window): string => implode('|', [
                $window->weekday, $window->starts_at_minutes, $window->ends_at_minutes,
            ]))
            ->all();

        $after = array_map(static fn (WindowSpec $window): string => implode('|', [
            $window->weekday, $window->startsAtMinutes, $window->endsAtMinutes,
        ]), $spec->windows);

        sort($before);
        sort($after);

        return $before !== $after;
    }

    private function hostsChanged(Series $series, SeriesSpec $spec): bool
    {
        $before = $series->hosts
            ->map(static fn (SeriesHost $host): string => implode('|', [$host->host_type, $host->host_id, $host->role]))
            ->all();

        $after = array_map(
            static fn (HostAssignment $host): string => implode('|', [
                $host->host->getMorphClass(), (string) $host->host->getKey(), $host->role,
            ]),
            $spec->hosts,
        );

        sort($before);
        sort($after);

        return $before !== $after;
    }

    /**
     * @param  array<int, int>  $values
     * @return list<int>
     */
    private function sorted(array $values): array
    {
        $sorted = array_map(intval(...), array_values($values));
        sort($sorted);

        return $sorted;
    }
}
