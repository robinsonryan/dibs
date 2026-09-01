<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

enum AvailabilityStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Draft => $to === self::Published,
            self::Published => $to === self::Closed,
            self::Closed => $to === self::Published,
        };
    }
}
