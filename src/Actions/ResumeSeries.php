<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\SeriesResumed;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;
use RobinsonRyan\Dibs\Support\SlotStatusSweep;

/**
 * Offer a paused series' times again, out to the date asked for.
 *
 * The times that were retired on pause are simply **reopened** — the same rows,
 * with the same ids — rather than the days being deleted and remade. That is
 * the choice worth naming: remaking would have had to work out which slots the
 * pause had created and which were older, and any slip would have left two open
 * slots at one time. Reopening cannot duplicate anything.
 *
 * Only slots that never carried a booking are reopened, which is precisely the
 * set pause retired: a slot retired by a *geometry* regeneration is history
 * (R41, it is retired because it carries bookings) and stays that way.
 *
 * Then it **regenerates** rather than merely materialising, because a rule may
 * have been edited while it was paused (materialisation creates nothing for a
 * paused series, so those days are still standing on the old version) and
 * because a day put back under the rule by `FollowSeries` while it was paused
 * is waiting to be remade. Regeneration is the one code path that remakes a day.
 * Materialisation then fills in the dates that came due while it was paused,
 * out to `$through` — or, when the caller names none, to the same horizon
 * `RegenerateSeries` and `SweepSeries` derive (`max_horizon_days`, 90 days when
 * the series names none), so the three verbs cannot disagree about how far
 * ahead a series is open.
 */
final class ResumeSeries
{
    public function __invoke(Series $series, ?CarbonImmutable $through = null): Series
    {
        return DB::transaction(function () use ($series, $through): Series {
            $locked = Dibs::lock($series);

            if (! $locked instanceof Series) {
                throw (new ModelNotFoundException)->setModel($series::class, [$series->getKey()]);
            }

            if (! $locked->status->canTransitionTo(SeriesStatus::Active)) {
                throw InvalidTransition::for($locked, $locked->status, SeriesStatus::Active);
            }

            $locked->status = SeriesStatus::Active;
            $locked->save();

            $this->reopenWhatIsAhead($locked);

            (new RegenerateSeries)($locked, $through ?? $this->horizonOf($locked));

            DB::afterCommit(fn () => event(new SeriesResumed($locked)));

            return $locked;
        });
    }

    private function reopenWhatIsAhead(Series $series): void
    {
        SlotStatusSweep::reopen(
            Dibs::query(Slot::class)
                // Days that are still published: a closed day offers nothing,
                // whether it was closed by hand or by `RemoveOccurrenceWindow`
                // to say this block does not happen on this date, and resuming
                // the rule is not a reason to put its old times back in
                // `Slot::upcoming()`.
                ->whereIn('availability_id', Dibs::query(Availability::class)
                    ->where('series_id', $series->getKey())
                    ->where('status', AvailabilityStatus::Published->value)
                    ->select('id'))
                ->where('status', SlotStatus::Retired->value)
                ->where('starts_at', '>', Slot::instant(null))
                ->whereDoesntHave('bookings'),
        );
    }

    /**
     * The horizon the series itself names, read on its own calendar — the same
     * derivation `RegenerateSeries` and `SweepSeries` make.
     */
    private function horizonOf(Series $series): CarbonImmutable
    {
        return SeriesClock::today($series->timezone)->addDays($series->max_horizon_days ?? 90);
    }
}
