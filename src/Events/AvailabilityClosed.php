<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Availability;

final readonly class AvailabilityClosed
{
    public function __construct(
        public Availability $availability,
    ) {}
}
