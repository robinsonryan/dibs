<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * A host to assign to a booking, with the role it fills (D7). Supplied
 * explicitly by CreateDirectBooking; derived from the availability's pool by
 * BookSlot's auto-assign (D9).
 */
final readonly class HostAssignment
{
    public function __construct(
        public Model $host,
        public string $role = 'host',
    ) {}
}
