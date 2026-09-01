<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\SlotGrid;

/**
 * Change a published availability's window, duration or padding (§5.1, D6):
 * open slots with no booking history are regenerated, everything else stands.
 */
final class UpdateAvailabilityGeometry
{
    public function __invoke(Availability $availability, AvailabilityGeometry $geometry): Availability
    {
        return DB::transaction(function () use ($availability, $geometry): Availability {
            // Validate before writing anything: an invalid geometry changes nothing.
            $positions = SlotGrid::positions($geometry);

            // Decided from the locked row: a rival publish, close or geometry
            // edit queues behind this one, so draft-vs-published and the grid
            // that lands are read from the same serialised copy.
            $locked = Dibs::lock($availability);

            if (! $locked instanceof Availability) {
                throw (new ModelNotFoundException)->setModel($availability::class, [$availability->getKey()]);
            }

            $locked->fill([
                'starts_at' => $geometry->startsAt->utc(),
                'ends_at' => $geometry->endsAt->utc(),
                'slot_duration_minutes' => $geometry->slotDurationMinutes,
                'slot_padding_minutes' => $geometry->slotPaddingMinutes,
            ])->save();

            // A draft has no slots yet; publishing lays down the new grid.
            if ($locked->status !== AvailabilityStatus::Draft) {
                $this->regenerate($locked, $positions);
            }

            $locked->load('slots');

            return $locked;
        });
    }

    /**
     * @param  list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $positions
     */
    private function regenerate(Availability $availability, array $positions): void
    {
        // Every decision below is read from these locked rows, taken before a
        // single row is deleted, retired or laid down (R43).
        //
        // The predicates cannot be left inside the DELETE and UPDATE statements
        // themselves. Under READ COMMITTED, when such a statement waits on a row
        // a rival `BookSlot` holds, PostgreSQL re-checks the *target row* once
        // the rival commits but re-evaluates a `NOT EXISTS` subquery against the
        // statement's original snapshot — the booking just committed is
        // invisible, and a slot carrying a live claim gets retired out from
        // under it. Holding the row locks first makes the rival queue behind
        // this regeneration and re-validate the slot's status when it wakes.
        $slots = $this->slots($availability)->lockForUpdate()->get();

        // Read only once the locks are held, so this sees every booking a rival
        // committed while it was waiting for them.
        $withBookings = [];
        $withLiveClaims = [];

        if ($slots->isNotEmpty()) {
            $bookings = Dibs::query(Booking::class)
                ->whereIn('slot_id', $slots->modelKeys())
                ->get(['slot_id', 'status']);

            foreach ($bookings as $booking) {
                $withBookings[$booking->slot_id] = true;

                if ($booking->status === BookingStatus::Booked) {
                    $withLiveClaims[$booking->slot_id] = true;
                }
            }
        }

        // What stands whatever the new grid says (D6): held, booked, and the
        // open slots still holding a live claim — a partly-full capacity-N slot
        // is not history. They keep their position even when it now falls
        // outside the window, and the grid lays nothing over them.
        /** @var list<Slot> $standing */
        $standing = [];
        /** @var list<Slot> $undecided */
        $undecided = [];

        foreach ($slots as $slot) {
            // Already stepped aside on an earlier regeneration: neither deleted
            // (its bookings are history, D3) nor allowed to block a position.
            if ($slot->status === SlotStatus::Retired) {
                continue;
            }

            if ($slot->status !== SlotStatus::Open || isset($withLiveClaims[(string) $slot->getKey()])) {
                $standing[] = $slot;

                continue;
            }

            $undecided[] = $slot;
        }

        $survivors = $standing;
        /** @var array<int, true> $filled */
        $filled = [];
        /** @var list<string> $doomed */
        $doomed = [];
        /** @var list<string> $retiring */
        $retiring = [];

        foreach ($undecided as $slot) {
            $key = (string) $slot->getKey();
            $position = $this->positionOf($positions, $slot);

            // This slot already *is* one of the new grid's positions, to the
            // instant: it is not displaced by anything, so it keeps its row, its
            // id and its status, and that position is not laid down twice. A
            // position the grid would skip anyway — because a held or booked
            // slot overlaps it — cannot rescue a slot, or the regeneration would
            // leave an open slot straddling a survivor.
            if ($position !== null && ! isset($filled[$position]) && ! $this->overlapsAny($standing, $positions[$position])) {
                $filled[$position] = true;
                $survivors[] = $slot;

                continue;
            }

            // Displaced. An open slot no booking ever touched is simply replaced
            // by the new grid; one whose history is spent — cancelled,
            // completed, no-show — cannot be deleted (D3) and steps aside as
            // `retired` instead (R41): out of every listing, but its row and its
            // bookings stand, and its position is free to be reused.
            if (isset($withBookings[$key])) {
                $retiring[] = $key;

                continue;
            }

            $doomed[] = $key;
        }

        if ($doomed !== []) {
            $this->slots($availability)->whereKey($doomed)->delete();
        }

        if ($retiring !== []) {
            $this->slots($availability)->whereKey($retiring)->update(['status' => SlotStatus::Retired->value]);
        }

        foreach ($positions as $position) {
            if ($this->overlapsAny($survivors, $position)) {
                continue;
            }

            Dibs::query(Slot::class)->create([
                'availability_id' => $availability->getKey(),
                'starts_at' => $position['starts_at'],
                'ends_at' => $position['ends_at'],
                'location' => null,
                'capacity' => 1,
                'status' => SlotStatus::Open,
            ]);
        }
    }

    /**
     * This availability's slots, reached through the class-map rather than the
     * relation, so the read, the lock and the write all run on the connection
     * the transaction opened on — never on whatever connection hydrated the
     * model the caller handed in (R42).
     *
     * @return Builder<Slot>
     */
    private function slots(Availability $availability): Builder
    {
        return Dibs::query(Slot::class)->where('availability_id', $availability->getKey());
    }

    /**
     * The index of the generated position this slot exactly is — same start and
     * same end, to the instant — or null when the new grid has displaced it.
     *
     * @param  list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $positions
     */
    private function positionOf(array $positions, Slot $slot): ?int
    {
        foreach ($positions as $index => $position) {
            if ($position['starts_at']->equalTo($slot->starts_at) && $position['ends_at']->equalTo($slot->ends_at)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<Slot>  $slots
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}  $position
     */
    private function overlapsAny(array $slots, array $position): bool
    {
        foreach ($slots as $slot) {
            if ($position['starts_at']->lessThan($slot->ends_at) && $position['ends_at']->greaterThan($slot->starts_at)) {
                return true;
            }
        }

        return false;
    }
}
