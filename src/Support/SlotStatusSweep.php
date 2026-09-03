<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Illuminate\Database\Eloquent\Builder;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * Move a set of slots to one status, from their locked rows.
 *
 * The one place the package does that, because the reason it has to lock first
 * is subtle enough to be worth writing down once: under READ COMMITTED an
 * `UPDATE` that waits on a row a rival `BookSlot` holds re-evaluates its own
 * subquery against the *original* snapshot, so a slot that had just been
 * claimed could be retired out from under the claim. Selecting `FOR UPDATE`
 * first and updating by key cannot do that — the rival's claim is either
 * already visible to the select, or it queues behind it.
 *
 * `PauseSeries` retires, `ResumeSeries` reopens, `SweepSeries` retires what has
 * passed, and `RegenerateSeries` retires the grid of a released day. The caller
 * supplies the filter; this supplies the locking.
 */
final class SlotStatusSweep
{
    /**
     * @param  Builder<Slot>  $slots
     * @return int slots retired
     */
    public static function retire(Builder $slots): int
    {
        return self::apply($slots, SlotStatus::Retired);
    }

    /**
     * @param  Builder<Slot>  $slots
     * @return int slots reopened
     */
    public static function reopen(Builder $slots): int
    {
        return self::apply($slots, SlotStatus::Open);
    }

    /**
     * @param  Builder<Slot>  $slots
     */
    private static function apply(Builder $slots, SlotStatus $status): int
    {
        $locked = $slots->lockForUpdate()->get();

        if ($locked->isEmpty()) {
            return 0;
        }

        return Dibs::query(Slot::class)
            ->whereKey($locked->modelKeys())
            ->update(['status' => $status->value]);
    }
}
