<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Availability;
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
        // An open slot no booking ever touched is simply replaced by the new grid.
        $this->slots($availability)
            ->open()
            ->whereDoesntHave('bookings')
            ->delete();

        // What is still open carries booking history, which is never deleted
        // (D3). Where that history is spent — cancelled, completed, no-show —
        // the slot steps aside as `retired`: out of every listing, but its row
        // and its bookings stand (R41). An open slot still holding a live claim
        // (a partly-full capacity-N slot) is not history, and is left alone.
        $this->slots($availability)
            ->open()
            ->whereDoesntHave('activeBookings')
            ->update(['status' => SlotStatus::Retired->value]);

        // Everything left standing holds its position, even when it now falls
        // outside the window (D6): held, booked, and the open slots with live
        // claims. Only a retired slot steps aside, and its position is reused.
        $survivors = $this->slots($availability)
            ->where(Dibs::make(Slot::class)->qualifyColumn('status'), '!=', SlotStatus::Retired->value)
            ->get();

        foreach ($positions as $position) {
            if ($this->overlapsSurvivor($survivors, $position)) {
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
     * @param  Collection<int, Slot>  $survivors
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}  $position
     */
    private function overlapsSurvivor(Collection $survivors, array $position): bool
    {
        return $survivors->contains(
            fn (Slot $slot): bool => $position['starts_at']->lessThan($slot->ends_at)
                && $position['ends_at']->greaterThan($slot->starts_at),
        );
    }
}
