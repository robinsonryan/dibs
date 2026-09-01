<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Booking;

final readonly class BookingCreated
{
    public function __construct(
        public Booking $booking,
    ) {}
}
