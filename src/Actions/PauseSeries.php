<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\SeriesPaused;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SlotStatusSweep;

/**
 * Stop offering a series' times, without destroying anything.
 *
 * Every time still ahead that nobody has claimed steps aside as `retired`, so
 * it leaves every bookable listing; a time somebody has booked is untouched,
 * because pausing a rule is not a reason to break an appointment. Days somebody
 * detached are retired too — a paused series offers nothing, and a day that
 * follows its own hours is still one of its days.
 *
 * `ResumeSeries` reopens exactly these rows, so nothing is lost and nothing is
 * duplicated.
 */
final class PauseSeries
{
    public function __invoke(Series $series): Series
    {
        return DB::transaction(function () use ($series): Series {
            $locked = Dibs::lock($series);

            if (! $locked instanceof Series) {
                throw (new ModelNotFoundException)->setModel($series::class, [$series->getKey()]);
            }

            if (! $locked->status->canTransitionTo(SeriesStatus::Paused)) {
                throw InvalidTransition::for($locked, $locked->status, SeriesStatus::Paused);
            }

            $locked->status = SeriesStatus::Paused;
            $locked->save();

            $this->retireWhatIsAhead($locked);

            DB::afterCommit(fn () => event(new SeriesPaused($locked)));

            return $locked;
        });
    }

    private function retireWhatIsAhead(Series $series): void
    {
        // Locked before the update rather than filtered inside it — the reason
        // lives in SlotStatusSweep, which is the one place this package moves a
        // set of slots to a status.
        SlotStatusSweep::retire(
            Dibs::query(Slot::class)
                ->whereIn('availability_id', Dibs::query(Availability::class)
                    ->where('series_id', $series->getKey())
                    ->select('id'))
                ->where('status', SlotStatus::Open->value)
                ->where('starts_at', '>', Slot::instant(null))
                ->whereDoesntHave('activeBookings'),
        );
    }
}
