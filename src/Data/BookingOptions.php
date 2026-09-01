<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * Options for BookSlot.
 *
 * `context` is the owning scope stamped on the booking; when null the slot's
 * availability's context is copied (direct bookings have none to copy).
 *
 * `viaOffer` is the D11 switch: set only by AcceptOffer, it lets a held slot be
 * booked, accepts a closed availability, and skips the notice/horizon checks —
 * the person was invited explicitly.
 */
final readonly class BookingOptions
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public bool $guardHostOverlap = false,
        public ?string $type = null,
        public array $meta = [],
        public bool $viaOffer = false,
        public ?Model $context = null,
    ) {}
}
