<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

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

    /**
     * Refuse a spec that could not describe a bookable slot, before any row is
     * written: an inverted or zero-length window, or one already in the past.
     * Called by every action that turns a spec into a slot, so the two paths
     * cannot diverge.
     *
     * @throws InvalidArgumentException
     */
    public function ensureValid(): void
    {
        if ($this->endsAt->lessThanOrEqualTo($this->startsAt)) {
            throw new InvalidArgumentException('An adhoc slot must end after it starts.');
        }

        if ($this->startsAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new InvalidArgumentException('An adhoc slot must start in the future.');
        }
    }
}
