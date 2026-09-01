<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Carbon\CarbonImmutable;

/**
 * The parameters that determine an availability's slot grid (D1). Buffers live
 * here, not on slots.
 */
final readonly class AvailabilityGeometry
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $slotDurationMinutes,
        public int $slotPaddingMinutes = 0,
    ) {}
}
