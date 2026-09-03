<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\SeriesResumed;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

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
 * Materialisation then fills in the dates that came due while it was paused.
 */
final class ResumeSeries
{
    public function __invoke(Series $series, CarbonImmutable $through): Series
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

            (new MaterialiseSeries)($locked, $through);

            DB::afterCommit(fn () => event(new SeriesResumed($locked)));

            return $locked;
        });
    }

    private function reopenWhatIsAhead(Series $series): void
    {
        $slots = Dibs::query(Slot::class)
            ->whereIn('availability_id', Dibs::query(Availability::class)
                ->where('series_id', $series->getKey())
                ->select('id'))
            ->where('status', SlotStatus::Retired->value)
            ->where('starts_at', '>', Slot::instant(null))
            ->whereDoesntHave('bookings')
            ->lockForUpdate()
            ->get();

        if ($slots->isEmpty()) {
            return;
        }

        Dibs::query(Slot::class)
            ->whereKey($slots->modelKeys())
            ->update(['status' => SlotStatus::Open->value]);
    }
}
