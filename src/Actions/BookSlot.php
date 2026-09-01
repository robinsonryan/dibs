<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Data\BookingOptions;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\BookingCreated;
use RobinsonRyan\Dibs\Exceptions\HostOverlap;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\OverlapCheck;

/**
 * Claim a slot (§5.2) — the concurrency-critical path. Everything is decided
 * from the slot's locked row, so two sessions racing for the last unit of
 * capacity cannot both win.
 */
final class BookSlot
{
    public function __invoke(Slot $slot, Model $bookedFor, Model $bookedBy, BookingOptions $options = new BookingOptions): Booking
    {
        return DB::transaction(fn (): Booking => $this->claim($slot, $bookedFor, $bookedBy, $options));
    }

    /**
     * The booking internals, run inside the caller's transaction: lock,
     * validate, assign hosts, write, settle the slot's status, announce.
     * Shared so a direct booking is not a second code path (D4).
     *
     * `$hosts` null means auto-assign from the availability's pool (D9); a list
     * assigns exactly those hosts instead.
     *
     * @param  list<HostAssignment>|null  $hosts
     */
    public function claim(Slot $slot, Model $bookedFor, Model $bookedBy, BookingOptions $options, ?array $hosts = null): Booking
    {
        $locked = Dibs::query(Slot::class)
            ->whereKey($slot->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Slot) {
            throw SlotUnavailable::for($slot, 'it no longer exists');
        }

        $locked->load(['availability.hosts.host']);

        $availability = $locked->availability;

        $this->assertBookable($locked, $availability, $options);

        $assignments = $hosts ?? $this->autoAssign($availability);

        if ($options->guardHostOverlap) {
            $this->assertHostsAreFree($assignments, $locked);
        }

        $booking = $this->write($locked, $bookedFor, $bookedBy, $options, $availability);

        foreach ($assignments as $assignment) {
            Dibs::query(BookingHost::class)->create([
                'booking_id' => $booking->getKey(),
                'host_type' => $assignment->host->getMorphClass(),
                'host_id' => (string) $assignment->host->getKey(),
                'role' => $assignment->role,
            ]);
        }

        $this->settle($locked);

        $booking->load(['slot.availability', 'hosts', 'bookedFor', 'bookedBy']);

        DB::afterCommit(fn () => event(new BookingCreated($booking)));

        return $booking;
    }

    private function assertBookable(Slot $slot, ?Availability $availability, BookingOptions $options): void
    {
        // The offer path books the very slot the offer holds.
        $bookableStatuses = $options->viaOffer
            ? [SlotStatus::Open, SlotStatus::Held]
            : [SlotStatus::Open];

        if (! in_array($slot->status, $bookableStatuses, true)) {
            throw SlotUnavailable::for($slot, sprintf('its status is %s', $slot->status->value));
        }

        if ($availability instanceof Availability) {
            // An outstanding offer is a promise: a since-closed availability
            // still honours it, but a draft one was never open for business (D11).
            $bookableAvailability = $options->viaOffer
                ? [AvailabilityStatus::Published, AvailabilityStatus::Closed]
                : [AvailabilityStatus::Published];

            if (! in_array($availability->status, $bookableAvailability, true)) {
                throw SlotUnavailable::for($slot, sprintf('its availability is %s', $availability->status->value));
            }
        }

        $now = CarbonImmutable::now('UTC');

        if ($slot->starts_at->lessThanOrEqualTo($now)) {
            throw SlotUnavailable::for($slot, 'it has already started');
        }

        if (! $options->viaOffer) {
            $this->assertInsideNoticeWindow($slot, $availability, $now);
        }

        if ($slot->activeBookings()->count() >= $slot->capacity) {
            throw SlotUnavailable::for($slot, 'it is fully booked');
        }
    }

    /**
     * Notice and horizon are availability parameters (D1); an adhoc slot has
     * neither. Skipped entirely on the offer path (D11).
     */
    private function assertInsideNoticeWindow(Slot $slot, ?Availability $availability, CarbonImmutable $now): void
    {
        if (! $availability instanceof Availability) {
            return;
        }

        $notice = $availability->min_notice_minutes;

        if ($notice !== null && $slot->starts_at->lessThan($now->addMinutes($notice))) {
            throw SlotUnavailable::for($slot, 'it is inside the minimum booking notice');
        }

        $horizon = $availability->max_horizon_days;

        if ($horizon !== null && $slot->starts_at->greaterThan($now->addDays($horizon))) {
            throw SlotUnavailable::for($slot, 'it is beyond the maximum booking horizon');
        }
    }

    /**
     * Every role whose pool holds exactly one host is assigned; a larger pool
     * waits for a consumer to choose (D9).
     *
     * @return list<HostAssignment>
     */
    private function autoAssign(?Availability $availability): array
    {
        if (! $availability instanceof Availability) {
            return [];
        }

        /** @var array<string, list<AvailabilityHost>> $pools */
        $pools = [];

        foreach ($availability->hosts as $pooled) {
            $pools[$pooled->role][] = $pooled;
        }

        $assignments = [];

        foreach ($pools as $role => $pool) {
            if (count($pool) !== 1) {
                continue;
            }

            $host = $pool[0]->host;

            // A pool row whose host record is gone cannot be assigned.
            if ($host instanceof Model) {
                $assignments[] = new HostAssignment($host, (string) $role);
            }
        }

        return $assignments;
    }

    /**
     * A query, not a solver (D8) — and it runs before anything is written.
     *
     * @param  list<HostAssignment>  $assignments
     */
    private function assertHostsAreFree(array $assignments, Slot $slot): void
    {
        foreach ($assignments as $assignment) {
            $overlapping = OverlapCheck::for($assignment->host, $slot->starts_at, $slot->ends_at);

            if ($overlapping->isNotEmpty()) {
                throw new HostOverlap($assignment->host, $overlapping);
            }
        }
    }

    private function write(Slot $slot, Model $bookedFor, Model $bookedBy, BookingOptions $options, ?Availability $availability): Booking
    {
        try {
            return Dibs::query(Booking::class)->create([
                'slot_id' => $slot->getKey(),
                'booked_for_type' => $bookedFor->getMorphClass(),
                'booked_for_id' => (string) $bookedFor->getKey(),
                'booked_by_type' => $bookedBy->getMorphClass(),
                'booked_by_id' => (string) $bookedBy->getKey(),
                // Denormalised at creation so later availability edits cannot
                // rewrite what this booking was for (D13).
                'type' => $options->type ?? $availability?->type,
                'status' => BookingStatus::Booked,
                'meta' => $options->meta,
            ]);
        } catch (QueryException $exception) {
            // The partial unique index on live claims.
            if ((string) $exception->getCode() === '23505') {
                throw SlotUnavailable::for($slot, 'that party already holds a live claim on it');
            }

            throw $exception;
        }
    }

    /**
     * The slot's status is an account of its live claims, never a cached guess.
     */
    private function settle(Slot $slot): void
    {
        $status = $slot->activeBookings()->count() >= $slot->capacity
            ? SlotStatus::Booked
            : SlotStatus::Open;

        if ($slot->status !== $status) {
            $slot->status = $status;
            $slot->save();
        }
    }
}
