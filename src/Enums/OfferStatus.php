<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

enum OfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function canTransitionTo(self $to): bool
    {
        return $this === self::Pending && $to !== self::Pending;
    }
}
