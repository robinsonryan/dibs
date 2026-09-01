<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Models\Booking;

final class HostOverlap extends DibsException
{
    /**
     * @param  Collection<int, Booking>  $overlapping
     */
    public function __construct(
        public readonly Model $host,
        public readonly Collection $overlapping,
    ) {
        parent::__construct(sprintf(
            '%s %s already has %d overlapping active booking(s).',
            class_basename($host),
            $host->getKey(),
            $overlapping->count(),
        ));
    }
}
