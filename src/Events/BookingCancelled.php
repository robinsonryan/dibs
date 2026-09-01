<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Booking;

final readonly class BookingCancelled
{
    public function __construct(
        public Booking $booking,
    ) {}
}
