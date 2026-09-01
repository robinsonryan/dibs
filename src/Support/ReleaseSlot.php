<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * The origin rule (D3): a slot let go of by an offer or a cancelled booking
 * reverts to `open` if it was born of an availability, while an adhoc slot no
 * booking ever touched is deleted. A slot carrying any booking row — even a
 * cancelled one — is never deleted; bookings are history.
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

        if ($locked->isAdhoc() && $locked->bookings()->count() === 0) {
            $locked->delete();

            return;
        }

        $status = $locked->activeBookings()->count() >= $locked->capacity
            ? SlotStatus::Booked
            : SlotStatus::Open;

        if ($locked->status !== $status) {
            $locked->status = $status;
            $locked->save();
        }
    }
}
