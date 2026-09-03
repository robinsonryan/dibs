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
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;
use RobinsonRyan\Dibs\Support\SlotStatusSweep;

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
 * edited that one deliberately. And it does not touch a day carrying a **live
 * claim** — a booking, or a held slot an offer is waiting on. Both are promises
 * to a person, so the consumer settles those first (`FindSeriesConflicts`, then
 * cancel or `ReparentSlotAsAdhoc`; withdraw or let the offer lapse) and the day
 * is caught up afterwards, by the next edit or by the nightly `SweepSeries`,
 * which regenerates as well as materialises for exactly this reason.
 *
 * The held case was a hole until 2026-09-03: a day whose only claim was a
 * pending offer looked clean, was deleted, and `dibs_offer_slots` cascaded — the
 * invitee's link pointed at nothing and nobody was told. The deletion now goes
 * through `DeleteAvailability`, whose held-slot refusal is the same one every
 * other caller meets, and a refusal simply leaves the day standing.
 *
 * A day whose bookings are all spent — cancelled, completed, no-show — is the
 * awkward middle. It cannot be deleted, because bookings are history and the
 * schema refuses to drop a slot that carries one (D3). So it is **released**
 * instead: closed, its remaining open times retired, and cut loose from the
 * series. Its record stands with its bookings, it offers nothing further, and
 * the date is free for the new rule to take. Ruled 2026-09-03, because the
 * alternative was an edit that crashed whenever somebody had once cancelled.
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
     * a live claim.
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
        $held = $this->idsWhere($stale, static fn (Builder $slots): Builder => $slots->where(
            Dibs::make(Slot::class)->qualifyColumn('status'),
            SlotStatus::Held->value,
        ));
        $spent = $this->idsWhere($stale, static fn (Builder $slots): Builder => $slots->has('bookings'));

        foreach ($stale as $occurrence) {
            $id = (string) $occurrence->getKey();

            // A booking or a pending offer is a live claim: the day stands as
            // it is, on its old version, until the claim is settled.
            if (isset($live[$id]) || isset($held[$id])) {
                continue;
            }

            if (isset($spent[$id])) {
                $this->release($occurrence);

                continue;
            }

            try {
                (new DeleteAvailability)($occurrence);
            } catch (DeletionRefused) {
                // A hold taken between the read above and the delete. The guard
                // caught it; the day keeps its stale version and is caught up
                // on the next regeneration, exactly as a held day found up
                // front is.
            }
        }
    }

    /**
     * Closed and cut loose: the row and its bookings stand as history, every
     * time it still offers steps aside, and its place in the series is free.
     *
     * Retiring the grid is what makes "it offers nothing further" true at the
     * slot level as well as the availability level — closing alone leaves the
     * old times in `Slot::upcoming()`, beside the remade day's, and a later
     * `PublishAvailability` would put the whole availability back in
     * `bookable()` with them. Retired slots are never revived by publishing.
     *
     * `meta.released_from_series` is the back-reference that keeps `DeleteSeries`
     * honest: the day no longer points at the series, but it still remembers
     * which rule it came from, so "any day that ever carried a booking" can
     * still be asked.
     */
    private function release(Availability $occurrence): void
    {
        if ($occurrence->status === AvailabilityStatus::Published) {
            (new CloseAvailability)($occurrence);
        }

        SlotStatusSweep::retire(
            Dibs::query(Slot::class)
                ->where('availability_id', $occurrence->getKey())
                ->where('status', SlotStatus::Open->value)
                ->whereDoesntHave('activeBookings'),
        );

        $meta = $occurrence->meta;
        $meta['released_from_series'] = (string) $occurrence->series_id;

        $occurrence->forceFill([
            'series_id' => null,
            'occurs_on' => null,
            'window_index' => null,
            'rule_version' => null,
            'meta' => $meta,
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
