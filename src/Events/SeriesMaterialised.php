<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use Illuminate\Database\Eloquent\Collection;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;

/**
 * Occurrences were laid down from a rule. Carries the ones this run created —
 * never the ones that were already there — so a consumer can stamp its own
 * columns on new rows without re-reading the whole horizon.
 */
final readonly class SeriesMaterialised
{
    /**
     * @param  Collection<int, Availability>  $occurrences
     */
    public function __construct(
        public Series $series,
        public Collection $occurrences,
    ) {}
}
