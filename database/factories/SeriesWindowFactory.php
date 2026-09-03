<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesWindow;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<SeriesWindow>
 */
final class SeriesWindowFactory extends Factory
{
    protected $model = SeriesWindow::class;

    public function modelName(): string
    {
        return Dibs::model(SeriesWindow::class);
    }

    /**
     * Tuesday evening, 6:00–8:00 in the series' own timezone.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'series_id' => Dibs::model(Series::class)::factory(),
            'weekday' => 2,
            'starts_at_minutes' => 18 * 60,
            'ends_at_minutes' => 20 * 60,
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
