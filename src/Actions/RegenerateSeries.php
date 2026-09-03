<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesWindow;
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
 * Two things it will not do. It does not touch the past: a day that has
 * happened is a record, not a plan. And it does not touch a detached day:
 * somebody edited that one deliberately.
 *
 * A day carrying a **live claim** — a booking, or a held slot an offer is
 * waiting on — is never deleted, because a claim is a promise to a person. It
 * is not simply skipped either: if the new rule still opens that date and that
 * block, and every claimed time still falls inside the new hours, the day is
 * **reshaped in place** — `UpdateAvailabilityGeometry` moves its window to the
 * rule's, regenerating the open times around the claimed ones, which keep their
 * rows and their ids — and stamped with the current version, its pool and the
 * things it carries brought into line. Widening 6–8 to 6–9 around an
 * appointment therefore opens the extra hour, instead of leaving the day at 6–8
 * with nothing to say it was left behind.
 *
 * Where the claim would not survive the move — the rule no longer opens that
 * date or that block, or a booked time now falls outside the hours — the day is
 * left standing on its old version, exactly as before. The consumer settles
 * those first (`FindSeriesConflicts`, then cancel or `ReparentSlotAsAdhoc`;
 * withdraw or let the offer lapse), and the day is caught up by the next edit
 * or by the nightly `SweepSeries`, which regenerates as well as materialises
 * for exactly this reason.
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
     * `$through` is how far the materialisation that follows reaches. Omitted,
     * it is the series' own horizon — three months when it names none, which is
     * far enough that a leader sees the rule working and near enough that an
     * edit does not rewrite a year of rows. `ResumeSeries` passes the date its
     * caller asked for.
     *
     * @return int occurrences created by the materialisation that follows
     */
    public function __invoke(Series $series, ?CarbonImmutable $through = null): int
    {
        return DB::transaction(function () use ($series, $through): int {
            $locked = Dibs::lock($series);

            if (! $locked instanceof Series) {
                throw (new ModelNotFoundException)->setModel($series::class, [$series->getKey()]);
            }

            $today = SeriesClock::today($locked->timezone);

            $this->clearStale($locked, $today);

            return (new MaterialiseSeries)(
                $locked,
                $through ?? $today->addDays($locked->max_horizon_days ?? 90),
            );
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

            // A booking or a pending offer is a live claim: the day is never
            // deleted. It is remade in place where the new rule still covers
            // every claim, and left standing on its old version where it does
            // not — until the consumer settles the claim.
            if (isset($live[$id]) || isset($held[$id])) {
                $this->reshape($series, $occurrence);

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
     * Bring a claimed day onto the current rule without disturbing the claim.
     *
     * Refused — and the day simply left as it is — when the rule no longer opens
     * that date or has no block at that day's index, when the hours are a
     * daylight-saving gap on that date, or when a claimed time would end up
     * outside the new window. `UpdateAvailabilityGeometry` would keep such a
     * slot (D6, held and booked slots always stand), but a day whose 7 pm
     * appointment sits outside its own 9-to-11 window is not a day that follows
     * the rule, and stamping it with the current version would say it was.
     */
    private function reshape(Series $series, Availability $occurrence): void
    {
        $date = $occurrence->occurs_on;
        $index = $occurrence->window_index;

        if (! $date instanceof CarbonImmutable || $index === null || ! $series->occursOn($date)) {
            return;
        }

        $window = $series->blocks()[$date->dayOfWeek][$index] ?? null;

        if (! $window instanceof SeriesWindow) {
            return;
        }

        $opens = SeriesClock::instantOn($date, $window->starts_at_minutes, $series->timezone);
        $closes = SeriesClock::instantOn($date, $window->ends_at_minutes, $series->timezone);

        if ($closes->lessThanOrEqualTo($opens) || ! $this->claimsFitInside($occurrence, $opens, $closes)) {
            return;
        }

        $reshaped = (new UpdateAvailabilityGeometry)($occurrence, new AvailabilityGeometry(
            $opens,
            $closes,
            $series->slot_duration_minutes,
            $series->slot_padding_minutes,
        ));

        $this->syncPool($series, $reshaped);

        // Everything a day carries comes from the rule too, so the stamp is
        // honest: this day is now this version of the rule, not just its hours.
        $reshaped->forceFill([
            'name' => $series->title,
            'location' => $series->location,
            'meta' => $series->meta,
            'min_notice_minutes' => $series->min_notice_minutes,
            'max_horizon_days' => $series->max_horizon_days,
            'rule_version' => $series->rule_version,
        ])->save();
    }

    /**
     * Would every claimed time on this day still fall inside the new hours?
     * Held slots count: an offer somebody is deciding on is as much a promise
     * as a booking.
     */
    private function claimsFitInside(Availability $occurrence, CarbonImmutable $opens, CarbonImmutable $closes): bool
    {
        $claimed = Dibs::query(Slot::class)
            ->where('availability_id', $occurrence->getKey())
            ->where(static function (Builder $slots): void {
                $slots
                    ->where(Dibs::make(Slot::class)->qualifyColumn('status'), SlotStatus::Held->value)
                    ->orHas('activeBookings');
            })
            ->get();

        foreach ($claimed as $slot) {
            if ($slot->starts_at->lessThan($opens) || $slot->ends_at->greaterThan($closes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The day's copy of the pool, brought back into line with the rule's.
     * Assignments already made on a booking are a different table and are never
     * touched: who is conducting an appointment somebody has is not a detail of
     * the rule.
     */
    private function syncPool(Series $series, Availability $occurrence): void
    {
        $wanted = [];

        foreach ($series->hosts as $host) {
            $wanted[implode('|', [$host->host_type, $host->host_id, $host->role])] = $host;
        }

        foreach ($occurrence->hosts()->get() as $pooled) {
            $key = implode('|', [$pooled->host_type, $pooled->host_id, $pooled->role]);

            if (isset($wanted[$key])) {
                unset($wanted[$key]);

                continue;
            }

            $pooled->delete();
        }

        foreach ($wanted as $host) {
            $occurrence->hosts()->create([
                'host_type' => $host->host_type,
                'host_id' => $host->host_id,
                'role' => $host->role,
            ]);
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
