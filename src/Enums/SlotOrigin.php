<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

/**
 * Derived, never stored: a slot with an availability_id was generated from an
 * Availability; one without is adhoc (a direct booking or an offer's own slot).
 */
enum SlotOrigin: string
{
    case Availability = 'availability';
    case Adhoc = 'adhoc';
}
