<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Exceptions\InvalidGeometry;

/**
 * The slot grid an availability's geometry describes (§5.1): from the window
 * start, place a slot of `slotDurationMinutes`, advance by duration + padding,
 * and stop once a whole slot no longer fits. Trailing remainder is unused.
 */
final class SlotGrid
{
    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     *
     * @throws InvalidGeometry
     */
    public static function positions(AvailabilityGeometry $geometry): array
    {
        if ($geometry->slotDurationMinutes < 1) {
            throw new InvalidGeometry('Slot duration must be at least one minute.');
        }

        if ($geometry->slotPaddingMinutes < 0) {
            throw new InvalidGeometry('Slot padding cannot be negative.');
        }

        // Every instant the package handles is UTC (D10); the caller may hand
        // us a Carbon carrying any offset.
        $windowStart = $geometry->startsAt->utc();
        $windowEnd = $geometry->endsAt->utc();

        if ($windowEnd->lessThanOrEqualTo($windowStart)) {
            throw new InvalidGeometry('The availability window must end after it starts.');
        }

        $positions = [];
        $cursor = $windowStart;

        while (true) {
            $endsAt = $cursor->addMinutes($geometry->slotDurationMinutes);

            if ($endsAt->greaterThan($windowEnd)) {
                return $positions;
            }

            $positions[] = ['starts_at' => $cursor, 'ends_at' => $endsAt];
            $cursor = $endsAt->addMinutes($geometry->slotPaddingMinutes);
        }
    }
}
