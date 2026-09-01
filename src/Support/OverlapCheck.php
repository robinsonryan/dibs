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
        return self::query($host, $start, $end)->get();
    }

    /**
     * The same question asked of a slot about to be claimed, which is why the
     * slot's own bookings are not counted: one host seating two attendees in a
     * shared capacity-N slot is not double-booked with themselves (R19).
     *
     * @return Collection<int, Booking>
     */
    public static function forSlot(Model $host, Slot $slot): Collection
    {
        $booking = Dibs::make(Booking::class);

        return self::query($host, $slot->starts_at, $slot->ends_at)
            ->where($booking->qualifyColumn('slot_id'), '!=', (string) $slot->getKey())
            ->get();
    }

    /**
     * The host's active bookings in any role, unfetched — the shared spine of
     * the booking-time guard and of `Support\\HostAvailability` (D15).
     *
     * @return Builder<Booking>
     */
    public static function query(Model $host, CarbonInterface $start, CarbonInterface $end): Builder
    {
        $bookingHost = Dibs::make(BookingHost::class);

        return Dibs::query(Booking::class)
            ->active()
            ->whereHas('hosts', fn (Builder $hosts): Builder => $hosts
                ->where($bookingHost->qualifyColumn('host_type'), $host->getMorphClass())
                ->where($bookingHost->qualifyColumn('host_id'), (string) $host->getKey()))
            ->whereHas('slot', fn (Builder $slots): Builder => self::overlappingSlots($slots, $start, $end));
    }

    /**
     * The one definition of "overlaps" in this package: a slot overlaps
     * `[$start, $end)` when it starts before the end and ends after the start,
     * so a slot that ends exactly when the window opens does not conflict.
     * Every Eloquent caller reads it here. `Slot::bookable(requireFreeHost:)`
     * restates it as column-to-column SQL because it compares two slot rows
     * rather than a row against two bound instants (B37).
     *
     * @param  Builder<Model>  $slots
     * @return Builder<Model>
     */
    public static function overlappingSlots(Builder $slots, CarbonInterface $start, CarbonInterface $end): Builder
    {
        $slot = Dibs::make(Slot::class);

        return $slots
            ->where($slot->qualifyColumn('starts_at'), '<', Slot::instant($end))
            ->where($slot->qualifyColumn('ends_at'), '>', Slot::instant($start));
    }
}
