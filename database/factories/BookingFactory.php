<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<Booking>
 */
final class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function modelName(): string
    {
        return Dibs::model(Booking::class);
    }

    /**
     * The party morphs are placeholders until `for()` / `by()` name real
     * consumer models. Creates a plain row: no slot status bookkeeping,
     * no events — use BookSlot for the real thing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $party = (string) Str::orderedUuid();

        return [
            'slot_id' => Dibs::model(Slot::class)::factory(),
            'booked_for_type' => 'party',
            'booked_for_id' => $party,
            'booked_by_type' => 'party',
            'booked_by_id' => $party,
            'type' => null,
            'status' => BookingStatus::Booked,
            'cancelled_at' => null,
            'meta' => [],
        ];
    }

    /**
     * Subject of the booking; also the submitter unless `by()` says otherwise.
     */
    public function bookedFor(Model $party): self
    {
        return $this->state(fn (array $attributes): array => [
            'booked_for_type' => $party->getMorphClass(),
            'booked_for_id' => (string) $party->getKey(),
            'booked_by_type' => $party->getMorphClass(),
            'booked_by_id' => (string) $party->getKey(),
        ]);
    }

    public function bookedBy(Model $party): self
    {
        return $this->state(fn (): array => [
            'booked_by_type' => $party->getMorphClass(),
            'booked_by_id' => (string) $party->getKey(),
        ]);
    }

    public function booked(): self
    {
        return $this->state(fn (): array => ['status' => BookingStatus::Booked, 'cancelled_at' => null]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => ['status' => BookingStatus::Completed]);
    }

    public function noShow(): self
    {
        return $this->state(fn (): array => ['status' => BookingStatus::NoShow]);
    }

    public function cancelled(?Model $by = null): self
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now('UTC'),
            'cancelled_by_type' => $by?->getMorphClass(),
            'cancelled_by_id' => $by instanceof Model ? (string) $by->getKey() : null,
        ]);
    }

    public function forContext(Model $context): self
    {
        return $this->state(fn (): array => [
            'context_type' => $context->getMorphClass(),
            'context_id' => (string) $context->getKey(),
        ]);
    }

    public function type(?string $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}
