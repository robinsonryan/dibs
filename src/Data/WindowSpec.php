<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

/**
 * One stretch of hours on one weekday, as minutes from local midnight in the
 * series' timezone. `SeriesSpec` decides whether a set of these is coherent;
 * a WindowSpec on its own says nothing about the others.
 */
final readonly class WindowSpec
{
    public function __construct(
        public int $weekday,
        public int $startsAtMinutes,
        public int $endsAtMinutes,
    ) {}
}
