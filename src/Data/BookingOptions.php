<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * Options for BookSlot.
 *
 * `context` is the owning scope stamped on the booking; when null the slot's
 * availability's context is copied (direct bookings have none to copy).
 * `contextType`/`contextId` carry an already-stored scope verbatim (AcceptOffer
 * passes the offer's) and take precedence over `context`, so a tenant row that
 * no longer resolves to a model cannot lose the scope it was recorded under.
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
        public ?string $contextType = null,
        public ?string $contextId = null,
    ) {}

    /**
     * The scope to stamp: the stored pair, else the model, else nothing.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function contextPair(): array
    {
        if ($this->contextType !== null && $this->contextId !== null) {
            return [$this->contextType, $this->contextId];
        }

        if ($this->context instanceof Model) {
            return [$this->context->getMorphClass(), (string) $this->context->getKey()];
        }

        return [null, null];
    }
}
