<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Offer;

final readonly class OfferCreated
{
    public function __construct(
        public Offer $offer,
    ) {}
}
