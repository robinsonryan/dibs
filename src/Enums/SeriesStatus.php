<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

/**
 * A series' lifecycle. `Ended` is where a series goes when its end date has
 * passed — stored so it can be indexed, kept current by the sweep — and it is
 * terminal: an ended series is restarted by giving it a new end date and
 * resuming, never by transitioning back on its own.
 */
enum SeriesStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Active => $to === self::Paused || $to === self::Ended,
            self::Paused => $to === self::Active || $to === self::Ended,
            self::Ended => false,
        };
    }
}
