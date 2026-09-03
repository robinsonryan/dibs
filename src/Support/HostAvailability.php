<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Dibs\Contracts\HostResolver;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * Who is spoken for, and when: the read side of the booking-time overlap
 * guard (D15). A short, closed list of questions — the package reports which
 * hosts are free and never picks one (D8).
 *
 * A pool entry is not necessarily a person: `freeHosts` and `freeHolders` put
 * every entry through the bound `HostResolver` first, so a pool of positions
 * answers with whoever holds them at the slot. `busyBookings` and `isFree` ask
 * about a host that is already concrete and resolve nothing.
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
     * The people the availability's pool for `$role` stands for who are free
     * during `$slot`, as the host models themselves, in pool order.
     *
     * Each pool entry is put through the bound `HostResolver` at the slot's
     * start, so an entry naming a position yields whoever holds it then — none,
     * one, or several. Two entries resolving to the same person yield that
     * person once. A host whose record no longer resolves is dropped.
     *
     * @return Collection<int, Model>
     */
    public static function freeHosts(Availability $availability, Slot $slot, string $role = 'host'): Collection
    {
        return self::resolvedFreeHolders($availability, $slot, $role, null);
    }

    /**
     * The same answer across the whole pool, whatever role each entry fills —
     * the role-agnostic reading `Slot::bookable(requireFreeHost:)` and
     * `Slot::capacityFor()` take, where the question is simply whether anybody
     * can take the appointment.
     *
     * `$at` names the moment the pool is resolved at, defaulting to the slot's
     * own start; freeness is always measured across the slot itself.
     *
     * @return Collection<int, Model>
     */
    public static function freeHolders(Availability $availability, Slot $slot, ?CarbonInterface $at = null): Collection
    {
        return self::resolvedFreeHolders($availability, $slot, null, $at);
    }

    /**
     * @return Collection<int, Model>
     */
    private static function resolvedFreeHolders(Availability $availability, Slot $slot, ?string $role, ?CarbonInterface $at): Collection
    {
        $availabilityHost = Dibs::make(AvailabilityHost::class);

        $pool = $availability->hosts()
            ->when($role !== null, fn (Builder $hosts): Builder => $hosts->where($availabilityHost->qualifyColumn('role'), $role))
            // uuid v7 keys are creation-ordered, so this is the order the pool
            // was built in — "pool order" has to be a fact, not whatever the
            // planner returns (B40).
            ->orderBy($availabilityHost->qualifyColumn('id'))
            ->get();

        if ($pool->isEmpty()) {
            return new Collection;
        }

        $pool->load('host');

        $holders = self::resolvePool($pool, $at instanceof CarbonInterface ? $at : $slot->starts_at, $availability->context);

        if ($holders === []) {
            return new Collection;
        }

        $busy = self::busyAssignments($slot);

        $free = [];

        foreach ($holders as $key => $holder) {
            if (! $busy->contains($key)) {
                $free[] = $holder;
            }
        }

        return new Collection($free);
    }

    /**
     * The pool put through the bound resolver, keyed `host_type|host_id` so a
     * person two entries both stand for is counted once, in pool order. The
     * availability's context rides along, because a pooled position may be a
     * catalog row several contexts share.
     *
     * @param  EloquentCollection<int, AvailabilityHost>  $pool
     * @return array<string, Model>
     */
    private static function resolvePool(EloquentCollection $pool, CarbonInterface $at, ?Model $context): array
    {
        $resolver = app(HostResolver::class);
        $holders = [];

        foreach ($pool as $member) {
            $host = $member->host;

            if (! $host instanceof Model) {
                continue;
            }

            foreach ($resolver->resolve($host, $at, $context) as $holder) {
                $holders[self::key($holder->getMorphClass(), (string) $holder->getKey())] ??= $holder;
            }
        }

        return $holders;
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
