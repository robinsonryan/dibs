<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Carbon\CarbonImmutable;

/**
 * Describes an adhoc slot to be created (no availability): used by CreateOffer
 * for invitation times that were never published, and by CreateDirectBooking.
 */
final readonly class AdhocSlotSpec
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $location = null,
        public int $capacity = 1,
    ) {}
}
