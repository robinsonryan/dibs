<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

/**
 * `retired` is history: a slot that carried a booking (since cancelled) and was
 * then displaced by a regeneration of its availability's grid. It keeps its
 * bookings, never appears bookable or upcoming, and its grid position is reused.
 */
enum SlotStatus: string
{
    case Open = 'open';
    case Held = 'held';
    case Booked = 'booked';
    case Retired = 'retired';
}
