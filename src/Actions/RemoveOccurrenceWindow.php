<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SeriesClock;
use RobinsonRyan\Dibs\Support\SlotStatusSweep;

/**
 * "This block does not happen on this date" — and it stays not happening.
 *
 * Deleting the day would not have said that. An occurrence is keyed
 * `(series_id, occurs_on, window_index)` and materialisation lays down any key
 * that has no row, so a deleted day is simply remade by the next sweep, that
 * night. The key has to stay occupied for the absence to survive, which is what
 * this does: the day is **closed** (it leaves `bookable()`), its unclaimed times
 * are **retired** (so it leaves `Slot::upcoming()` too, and offers nothing at
 * all), and it is **detached** — so regeneration passes it by, materialisation
 * finds its key taken, and neither pause nor resume brings its times back,
 * because resume only reopens the times of days that are still published.
 *
 * Appointments already made stand. Closing a day is not cancelling anybody's
 * appointment (D6): a consumer that means to cancel them says so first, with
 * `CancelBooking`, and a time somebody is still deciding on stays held until
 * the offer is settled.
 *
 * `FollowSeries` is the way back: it puts the day under the rule again and
 * regeneration remakes it.
 */
final class RemoveOccurrenceWindow
{
    public function __invoke(Availability $occurrence): Availability
    {
        return DB::transaction(function () use ($occurrence): Availability {
            $locked = Dibs::lock($occurrence);

            if (! $locked instanceof Availability) {
                throw (new ModelNotFoundException)->setModel($occurrence::class, [$occurrence->getKey()]);
            }

            if ($locked->series_id === null) {
                throw InvalidSeries::because(
                    InvalidSeries::OCCURRENCE_NOT_IN_SERIES,
                    'Only an availability made by a series can have its window removed.',
                );
            }

            // Through the ordinary action, so the status machine and the
            // `AvailabilityClosed` event are the same ones every other closing
            // goes through; the in-memory copy is brought level with what it
            // just wrote. A draft occurrence is left as it is — it was never
            // offering anything.
            if ($locked->status === AvailabilityStatus::Published) {
                (new CloseAvailability)($locked);

                $locked->status = AvailabilityStatus::Closed;
            }

            SlotStatusSweep::retire(
                Dibs::query(Slot::class)
                    ->where('availability_id', $locked->getKey())
                    ->where('status', SlotStatus::Open->value)
                    ->whereDoesntHave('activeBookings'),
            );

            if (! $locked->isDetached()) {
                $locked->detached_at = SeriesClock::now();
            }

            $locked->save();

            $locked->load(['slots', 'hosts']);

            return $locked;
        });
    }
}
