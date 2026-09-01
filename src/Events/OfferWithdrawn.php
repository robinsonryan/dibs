<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Offer;

final readonly class OfferWithdrawn
{
    public function __construct(
        public Offer $offer,
    ) {}
}
