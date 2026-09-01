<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Data\BookingOptions;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * A direct appointment is not a second code path (D4): create the adhoc slot
 * and claim it in one transaction, through BookSlot's own internals.
 */
final class CreateDirectBooking
{
    /**
     * @param  list<HostAssignment>  $hosts
     */
    public function __invoke(Model $bookedFor, Model $bookedBy, AdhocSlotSpec $spec, array $hosts = [], BookingOptions $options = new BookingOptions): Booking
    {
        $spec->ensureValid();

        return DB::transaction(function () use ($bookedFor, $bookedBy, $spec, $hosts, $options): Booking {
            $slot = Dibs::query(Slot::class)->create([
                'availability_id' => null,
                'starts_at' => $spec->startsAt,
                'ends_at' => $spec->endsAt,
                'location' => $spec->location,
                'capacity' => $spec->capacity,
                'status' => SlotStatus::Open,
            ]);

            return (new BookSlot)->claim($slot, $bookedFor, $bookedBy, $options, $hosts);
        });
    }
}
