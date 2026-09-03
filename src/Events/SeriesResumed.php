<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Series;

final readonly class SeriesResumed
{
    public function __construct(
        public Series $series,
    ) {}
}
