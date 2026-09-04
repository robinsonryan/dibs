<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Models\UnavailabilityWindow;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<UnavailabilityWindow>
 */
final class UnavailabilityWindowFactory extends Factory
{
    protected $model = UnavailabilityWindow::class;

    public function modelName(): string
    {
        return Dibs::model(UnavailabilityWindow::class);
    }

    /**
     * Sunday evening, 6:00–7:00 on the away's own clock.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unavailability_id' => Dibs::model(Unavailability::class)::factory(),
            'weekday' => 0,
            'starts_at_minutes' => 18 * 60,
            'ends_at_minutes' => 19 * 60,
        ];
    }

    public function on(int $weekday, int $startsAtMinutes, int $endsAtMinutes): self
    {
        return $this->state(fn (): array => [
            'weekday' => $weekday,
            'starts_at_minutes' => $startsAtMinutes,
            'ends_at_minutes' => $endsAtMinutes,
        ]);
    }
}
