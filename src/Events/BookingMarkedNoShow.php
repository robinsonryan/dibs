<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use RobinsonRyan\Dibs\Models\Booking;

final readonly class BookingMarkedNoShow
{
    public function __construct(
        public Booking $booking,
    ) {}
}
