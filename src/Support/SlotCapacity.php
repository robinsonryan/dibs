<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonInterface;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * How many appointments a slot can take — the one definition, so the gate that
 * refuses a claim, the step that settles a slot's status, the release path and
 * the number a consumer is shown can never disagree.
 *
 * **The `capacity` column decides, unless it is null** (D18, narrowed in
 * 0.3.4). A number is the cap wherever it is read — a pool on the availability
 * is then a *candidate list*, the people who might take the appointment, and
 * not a count of how many the time seats. A null column is the other kind of
 * time: it is measured by who is free — the people its availability's pool
 * resolves to (D17) with nothing booked across it elsewhere — so three free
 * interviewers at six o'clock are three appointments at six o'clock. A null
 * column with no pool behind it has nobody to be measured by and seats one.
 *
 * Two readings, one rule. `forClaim()` is what the booking and release paths
 * ask: `exclusive_hosts` is forced off, because those callers subtract the
 * slot's own claims by counting them and letting a claim take its holder out
 * here as well would cost the slot an appointment for every one it already has.
 * `of()` is the reporting reading `Slot::capacityFor()` takes, where the config
 * stands, so with exclusive hosts a holder already claimed on the slot drops
 * out and the number is what is *left*.
 */
final class SlotCapacity
{
    /**
     * What a pool-derived slot seats when there is no pool to derive from —
     * one appointment, which is what the column's own default says.
     */
    private const WITHOUT_A_POOL = 1;

    /**
     * The number `BookSlot` gates on, `BookSlot` settles against, and
     * `Support\ReleaseSlot` settles back against.
     */
    public static function forClaim(Slot $slot, ?Availability $availability = null): int
    {
        return self::of($slot, $availability, null, false);
    }

    /**
     * `$at` names the moment the pool is resolved at, defaulting to the slot's
     * own start — who holds the position when the appointment happens.
     */
    public static function of(Slot $slot, ?Availability $availability = null, ?CarbonInterface $at = null, ?bool $exclusiveHosts = null): int
    {
        $column = $slot->capacity;

        if ($column !== null) {
            return $column;
        }

        $availability ??= $slot->availability;

        if (! $availability instanceof Availability) {
            return self::WITHOUT_A_POOL;
        }

        // The property, not `hosts()->doesntExist()`: a caller that has already
        // eager-loaded the pool (the booking path does) pays no second query.
        if ($availability->hosts->isEmpty()) {
            return self::WITHOUT_A_POOL;
        }

        return HostAvailability::freeHolders($availability, $slot, $at, $exclusiveHosts)->count();
    }
}
