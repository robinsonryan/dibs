<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Events;

use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Models\Booking;

/**
 * A role on a booking changed hands (D14). `$previousHost` is the host displaced
 * by this assignment — null when the role was unassigned, or when the displaced
 * host's record no longer resolves (B35).
 */
final readonly class BookingHostAssigned
{
    public function __construct(
        public Booking $booking,
        public Model $host,
        public string $role,
        public ?Model $previousHost = null,
    ) {}
}
