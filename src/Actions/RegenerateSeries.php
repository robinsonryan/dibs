<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;

/**
 * Bring a series' future days back into line with its current rule, then open
 * the horizon again.
 *
 * It never diffs. `rule_version` already says "the rule moved", so any future
 * day still stamped with an older version is simply remade — which is both
 * simpler than working out what changed and impossible to get subtly wrong.
 *
 * Three things it will not do. It does not touch the past: a day that has
 * happened is a record, not a plan. It does not touch a detached day: somebody
 * edited that one deliberately. And it does not touch a day carrying a live
 * booking — the appointment is a promise to a person, so the consumer settles
 * those first (`FindSeriesConflicts`, then cancel or `ReparentSlotAsAdhoc`) and
 * regenerates after.
 *
 * A day whose bookings are all spent — cancelled, completed, no-show — is the
 * awkward middle. It cannot be deleted, because bookings are history and the
 * schema refuses to drop a slot that carries one (D3). So it is **released**
 * instead: closed, and cut loose from the series. Its record stands with its
 * bookings, it offers nothing further, and the date is free for the new rule to
 * take. Ruled 2026-09-03, because the alternative was an edit that crashed
 * whenever somebody had once cancelled.
 */
final class RegenerateSeries
{
    /**
     * @return int occurrences created by the materialisation that follows
     */
    public function __invoke(Series $series): int
    {
        return DB::transaction(function () use ($series): int {
            $locked = Dibs::lock($series);

            if (! $locked instanceof Series) {
                throw (new ModelNotFoundException)->setModel($series::class, [$series->getKey()]);
            }

            $today = SeriesClock::today($locked->timezone);

            $this->clearStale($locked, $today);

            // Default horizon when the series names none: three months is far
            // enough that a leader sees the rule working and near enough that
            // an edit does not rewrite a year of rows.
            $through = $today->addDays($locked->max_horizon_days ?? 90);

            return (new MaterialiseSeries)($locked, $through);
        });
    }

    /**
     * Every future, following day still on an older version: deleted where it
     * is clean, released where it carries history, left alone where it carries
     * a live booking.
     */
    private function clearStale(Series $series, CarbonImmutable $today): void
    {
        // Locked before anything is read about them, so a booking landing
        // mid-decision queues behind this regeneration rather than slipping
        // between the check and the delete.
        $stale = Dibs::query(Availability::class)
            ->where('series_id', $series->getKey())
            ->whereNull('detached_at')
            ->where('occurs_on', '>=', $today->format('Y-m-d'))
            ->where('rule_version', '<', $series->rule_version)
            ->lockForUpdate()
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $live = $this->idsWhere($stale, static fn (Builder $slots): Builder => $slots->has('activeBookings'));
        $spent = $this->idsWhere($stale, static fn (Builder $slots): Builder => $slots->has('bookings'));

        foreach ($stale as $occurrence) {
            $id = (string) $occurrence->getKey();

            if (isset($live[$id])) {
                continue;
            }

            if (isset($spent[$id])) {
                $this->release($occurrence);

                continue;
            }

            $occurrence->delete();
        }
    }

    /**
     * Closed and cut loose: the row and its bookings stand as history, it
     * leaves every bookable listing, and its place in the series is free.
     */
    private function release(Availability $occurrence): void
    {
        if ($occurrence->status === AvailabilityStatus::Published) {
            (new CloseAvailability)($occurrence);
        }

        $occurrence->forceFill([
            'series_id' => null,
            'occurs_on' => null,
            'window_index' => null,
            'rule_version' => null,
        ])->save();
    }

    /**
     * @param  Collection<int, Availability>  $occurrences
     * @param  Closure(Builder<\Illuminate\Database\Eloquent\Model>): mixed  $constraint
     * @return array<string, true>
     */
    private function idsWhere(Collection $occurrences, Closure $constraint): array
    {
        $ids = Dibs::query(Availability::class)
            ->whereKey($occurrences->modelKeys())
            ->whereHas('slots', $constraint)
            ->pluck('id');

        $found = [];

        foreach ($ids as $id) {
            $found[(string) $id] = true;
        }

        return $found;
    }
}
