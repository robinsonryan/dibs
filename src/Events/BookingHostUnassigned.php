<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Models\Booking;

/**
 * A role on a booking was cleared (D14) — one event per host removed.
 */
final readonly class BookingHostUnassigned
{
    public function __construct(
        public Booking $booking,
        public string $role,
        public Model $previousHost,
    ) {}
}
