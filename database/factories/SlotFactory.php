<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<Slot>
 */
final class SlotFactory extends Factory
{
    protected $model = Slot::class;

    public function modelName(): string
    {
        return Dibs::model(Slot::class);
    }

    /**
     * By default an availability-born slot on a published availability.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now('UTC')->addDays(7)->startOfHour();

        return [
            'availability_id' => Dibs::model(Availability::class)::factory()->published(),
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(30),
            'location' => null,
            'capacity' => 1,
            'status' => SlotStatus::Open,
        ];
    }

    public function adhoc(?string $location = 'Room 1'): self
    {
        return $this->state(fn (): array => ['availability_id' => null, 'location' => $location]);
    }

    public function open(): self
    {
        return $this->state(fn (): array => ['status' => SlotStatus::Open]);
    }

    public function held(): self
    {
        return $this->state(fn (): array => ['status' => SlotStatus::Held]);
    }

    public function booked(): self
    {
        return $this->state(fn (): array => ['status' => SlotStatus::Booked]);
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['status' => SlotStatus::Retired]);
    }

    public function at(CarbonImmutable $startsAt, int $minutes = 30): self
    {
        return $this->state(fn (): array => ['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($minutes)]);
    }

    public function past(): self
    {
        return $this->at(CarbonImmutable::now('UTC')->subDay()->startOfHour());
    }

    public function capacity(int $capacity): self
    {
        return $this->state(fn (): array => ['capacity' => $capacity]);
    }
}
