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
 * A **pooled** slot is measured by who is free (D18): the people its
 * availability's pool resolves to (D17) with nothing booked across it
 * elsewhere. Three free interviewers at six o'clock are three appointments at
 * six o'clock, whatever the `capacity` column says. The column decides only
 * slots with no pool behind them — an adhoc slot, or an availability nobody was
 * pooled on — where there is nobody to be busy.
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
        $availability ??= $slot->availability;

        if (! $availability instanceof Availability) {
            return $slot->capacity;
        }

        // The property, not `hosts()->doesntExist()`: a caller that has already
        // eager-loaded the pool (the booking path does) pays no second query.
        if ($availability->hosts->isEmpty()) {
            return $slot->capacity;
        }

        return HostAvailability::freeHolders($availability, $slot, $at, $exclusiveHosts)->count();
    }
}
