<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Availability;

final readonly class AvailabilityPublished
{
    public function __construct(
        public Availability $availability,
    ) {}
}
