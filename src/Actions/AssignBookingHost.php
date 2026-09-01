<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\BookingHostAssigned;
use RobinsonRyan\Dibs\Exceptions\HostOverlap;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\OverlapCheck;

/**
 * Give a booking's role to a host, after the fact (D14) — a pool member taking
 * an unassigned booking, or an administrator reassigning one. Auto-assign (D9)
 * settles only the unambiguous cases; this settles the rest.
 *
 * Assigning is a **replace**: a role holds at most one host on a booking.
 */
final class AssignBookingHost
{
    /**
     * @throws InvalidTransition when the booking is cancelled
     * @throws HostOverlap when $guardHostOverlap and the host has an overlapping active booking
     */
    public function __invoke(Booking $booking, Model $host, string $role = 'host', bool $guardHostOverlap = false): Booking
    {
        return DB::transaction(function () use ($booking, $host, $role, $guardHostOverlap): Booking {
            // Decided from the locked row: a rival assignment or a cancellation
            // queues behind this one rather than both passing the guard.
            $locked = Dibs::lock($booking);

            if (! $locked instanceof Booking) {
                throw (new ModelNotFoundException)->setModel($booking::class, [$booking->getKey()]);
            }

            if ($locked->status === BookingStatus::Cancelled) {
                // A cancelled claim is frozen; a completed or no-show one may
                // still have its record corrected (D14).
                throw InvalidTransition::for($locked, $locked->status, BookingStatus::Booked);
            }

            $existing = $this->assignments($locked, $role);

            // Already theirs: no write, no event, nothing for a listener to undo.
            if ($this->isHeldBy($existing, $host)) {
                return $this->loaded($locked);
            }

            if ($guardHostOverlap) {
                $this->assertHostIsFree($host, $locked);
            }

            $displaced = $existing->first()?->host;

            $locked->hosts()->where('role', $role)->delete();

            $locked->hosts()->create([
                'host_type' => $host->getMorphClass(),
                'host_id' => (string) $host->getKey(),
                'role' => $role,
            ]);

            $this->loaded($locked);

            DB::afterCommit(fn () => event(new BookingHostAssigned(
                $locked,
                $host,
                $role,
                $displaced instanceof Model ? $displaced : null,
            )));

            return $locked;
        });
    }

    /**
     * The role's current assignments, oldest first — uuid v7 keys order by
     * creation, so `first()` is the host to report as displaced (B36).
     *
     * @return Collection<int, BookingHost>
     */
    private function assignments(Booking $booking, string $role): Collection
    {
        return $booking->hosts()->where('role', $role)->with('host')->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, BookingHost>  $existing
     */
    private function isHeldBy(Collection $existing, Model $host): bool
    {
        if ($existing->count() !== 1) {
            return false;
        }

        $assignment = $existing->first();

        return $assignment instanceof BookingHost
            && $assignment->host_type === $host->getMorphClass()
            && $assignment->host_id === (string) $host->getKey();
    }

    /**
     * The same question BookSlot asks at claim time, of the booking's own window:
     * the booking's slot is never its own conflict, so a shared capacity-N slot
     * may seat two parties under one host (R19).
     */
    private function assertHostIsFree(Model $host, Booking $booking): void
    {
        $slot = $booking->slot;

        // `slot_id` is a non-null FK, so this cannot happen; without a window
        // there is simply nothing to ask.
        if (! $slot instanceof Slot) {
            return;
        }

        $overlapping = OverlapCheck::forSlot($host, $slot);

        if ($overlapping->isNotEmpty()) {
            throw new HostOverlap($host, $overlapping);
        }
    }

    /**
     * The caller gets an answer to "who has it now?" without another query.
     */
    private function loaded(Booking $booking): Booking
    {
        $booking->load(['hosts.host']);

        return $booking;
    }
}
