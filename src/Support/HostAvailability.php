<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * Who is spoken for, and when: the read side of the booking-time overlap
 * guard (D15). Three questions and no fourth — the package reports which
 * hosts are free and never picks one (D8).
 */
final class HostAvailability
{
    /**
     * Active bookings (status `booked`) with this host assigned in any role
     * whose slot overlaps `[$start, $end)`, ordered by slot start.
     *
     * `$except` drops one booking from the answer, which is how a caller asks
     * "would this host be free if we ignored the booking they are about to
     * change?".
     *
     * @return EloquentCollection<int, Booking>
     */
    public static function busyBookings(Model $host, CarbonImmutable $start, CarbonImmutable $end, ?Booking $except = null): EloquentCollection
    {
        $booking = Dibs::make(Booking::class);
        $slot = Dibs::make(Slot::class);

        $query = OverlapCheck::query($host, $start, $end);

        if ($except instanceof Booking) {
            $query->whereKeyNot($except->getKey());
        }

        // Ordered by the slot's start through a correlated sub-select: the
        // caller gets Booking models, not a join's flattened columns.
        return $query
            ->orderBy(
                Dibs::query(Slot::class)
                    ->select($slot->qualifyColumn('starts_at'))
                    ->whereColumn($slot->qualifyColumn('id'), $booking->qualifyColumn('slot_id')),
            )
            ->get();
    }

    /**
     * Nothing of this host's is booked across `[$start, $end)`.
     */
    public static function isFree(Model $host, CarbonImmutable $start, CarbonImmutable $end, ?Booking $except = null): bool
    {
        return self::busyBookings($host, $start, $end, $except)->isEmpty();
    }

    /**
     * The availability's pool members for `$role` who are free during `$slot`,
     * returned as the host models themselves (resolved through the `host`
     * morph) in pool order. A host whose record no longer resolves is dropped.
     *
     * @return Collection<int, Model>
     */
    public static function freeHosts(Availability $availability, Slot $slot, string $role = 'host'): Collection
    {
        $availabilityHost = Dibs::make(AvailabilityHost::class);

        $pool = $availability->hosts()
            ->where($availabilityHost->qualifyColumn('role'), $role)
            // uuid v7 keys are creation-ordered, so this is the order the pool
            // was built in — "pool order" has to be a fact, not whatever the
            // planner returns (B40).
            ->orderBy($availabilityHost->qualifyColumn('id'))
            ->get();

        if ($pool->isEmpty()) {
            return new Collection;
        }

        $busy = self::busyAssignments($slot);

        $free = $pool->reject(
            fn (AvailabilityHost $member): bool => $busy->contains(self::key($member->host_type, $member->host_id)),
        );

        $free->load('host');

        $hosts = [];

        foreach ($free as $member) {
            $host = $member->host;

            if ($host instanceof Model) {
                $hosts[] = $host;
            }
        }

        return new Collection($hosts);
    }

    /**
     * The `host_type|host_id` pairs already spoken for during `$slot` — one
     * query for the whole pool, however large it is.
     *
     * Bookings on `$slot` itself do not count (D15/B38): a host seating two
     * attendees in one capacity-N slot is not double-booked with themselves,
     * which is the same rule `OverlapCheck::forSlot` applies at booking time.
     *
     * @return Collection<int, string>
     */
    private static function busyAssignments(Slot $slot): Collection
    {
        $booking = Dibs::make(Booking::class);
        $assignment = Dibs::make(BookingHost::class);

        $overlapping = Dibs::query(Booking::class)
            ->active()
            ->where($booking->qualifyColumn('slot_id'), '!=', (string) $slot->getKey())
            ->whereHas('slot', fn (Builder $slots): Builder => OverlapCheck::overlappingSlots(
                $slots,
                $slot->starts_at,
                $slot->ends_at,
            ))
            ->select($booking->qualifyColumn('id'));

        return Dibs::query(BookingHost::class)
            ->whereIn($assignment->qualifyColumn('booking_id'), $overlapping)
            ->get()
            ->map(fn (BookingHost $assignment): string => self::key($assignment->host_type, $assignment->host_id));
    }

    private static function key(string $type, string $id): string
    {
        return $type.'|'.$id;
    }
}
