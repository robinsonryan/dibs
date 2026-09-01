<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * Asks whether a host is already spoken for in a window. A query, never a
 * solver: the package does not compute joint availability (D8).
 */
final class OverlapCheck
{
    /**
     * The host's active bookings (any role) whose slot overlaps [$start, $end):
     * touching endpoints do not overlap. Public API whether or not a caller
     * opts into the booking-time guard (R19).
     *
     * @return Collection<int, Booking>
     */
    public static function for(Model $host, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $from = Slot::instant($start);
        $to = Slot::instant($end);

        $bookingHost = Dibs::make(BookingHost::class);
        $slot = Dibs::make(Slot::class);

        return Dibs::query(Booking::class)
            ->active()
            ->whereHas('hosts', fn (Builder $hosts): Builder => $hosts
                ->where($bookingHost->qualifyColumn('host_type'), $host->getMorphClass())
                ->where($bookingHost->qualifyColumn('host_id'), (string) $host->getKey()))
            ->whereHas('slot', fn (Builder $slots): Builder => $slots
                ->where($slot->qualifyColumn('starts_at'), '<', $to)
                ->where($slot->qualifyColumn('ends_at'), '>', $from))
            ->get();
    }
}
