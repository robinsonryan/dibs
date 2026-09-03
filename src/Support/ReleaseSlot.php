<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * The origin rule (D3): a slot let go of by an offer or a cancelled booking
 * reverts to `open` if it was born of an availability, while an adhoc slot no
 * booking ever touched is deleted. A slot carrying any booking row — even a
 * cancelled one — is never deleted; bookings are history.
 *
 * Two things decide what "reverts" means. **A paused series offers nothing**:
 * a slot on one of its days steps aside as `retired` instead of returning to
 * `open`, or an offer lapsing would put a time back on sale that the leader had
 * deliberately taken down, and the sweep skips paused series so it would stay
 * there until resume. Retiring is also what makes resume correct — it reopens
 * exactly the retired, never-booked future rows, so the slot comes back with
 * the rest of them and with its own id. (The alternative, teaching `bookable()`
 * to exclude paused series, would have left the row `open` and lying, and would
 * not have kept it out of `Slot::upcoming()`.)
 *
 * Otherwise the slot is `booked` or `open` by the same measure `BookSlot` uses
 * (`Support\SlotCapacity`): a pooled slot is full when its live claims reach the
 * number of free holders, never when they reach the `capacity` column, or a
 * cancellation on a 2-of-3 pooled slot would leave it looking full.
 *
 * Runs inside the caller's transaction, on the slot's locked row.
 */
final class ReleaseSlot
{
    public function __invoke(Slot $slot): void
    {
        $locked = Dibs::lock($slot);

        if (! $locked instanceof Slot) {
            return;
        }

        // A slot displaced by a regeneration is history: it keeps its bookings
        // and never returns to circulation (R41).
        if ($locked->status === SlotStatus::Retired) {
            return;
        }

        if ($locked->isAdhoc() && $locked->bookings()->count() === 0) {
            $locked->delete();

            return;
        }

        $availability = $locked->availability;

        $status = $this->seriesIsPaused($availability)
            ? SlotStatus::Retired
            : $this->settled($locked, $availability);

        if ($locked->status !== $status) {
            $locked->status = $status;
            $locked->save();
        }
    }

    private function settled(Slot $slot, ?Availability $availability): SlotStatus
    {
        return $slot->activeBookings()->count() >= SlotCapacity::forClaim($slot, $availability)
            ? SlotStatus::Booked
            : SlotStatus::Open;
    }

    private function seriesIsPaused(?Availability $availability): bool
    {
        if (! $availability instanceof Availability) {
            return false;
        }

        $series = $availability->series;

        return $series instanceof Series && $series->status === SeriesStatus::Paused;
    }
}
