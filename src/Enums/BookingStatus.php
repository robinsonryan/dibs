<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

enum BookingStatus: string
{
    case Booked = 'booked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * booked → completed | cancelled | no_show; completed ↔ no_show (both are
     * post-hoc judgments); cancelled is terminal.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Booked => $to !== self::Booked,
            self::Completed => $to === self::NoShow,
            self::NoShow => $to === self::Completed,
            self::Cancelled => false,
        };
    }
}
