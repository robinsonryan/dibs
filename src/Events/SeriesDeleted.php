<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Series;

/**
 * Carries the series as it was: the row is already gone by the time a listener
 * runs, so this is the only record of what was deleted.
 */
final readonly class SeriesDeleted
{
    public function __construct(
        public Series $series,
    ) {}
}
