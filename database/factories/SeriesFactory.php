<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<Series>
 */
final class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function modelName(): string
    {
        return Dibs::model(Series::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Series '.$this->faker->unique()->numberBetween(1, 1_000_000),
            'timezone' => 'UTC',
            'cadence' => Cadence::Weekly,
            'ordinals' => [],
            'starts_on' => CarbonImmutable::now('UTC')->startOfDay(),
            'ends_on' => null,
            'slot_duration_minutes' => 30,
            'slot_padding_minutes' => 0,
            'min_notice_minutes' => null,
            'max_horizon_days' => null,
            'location' => null,
            'status' => SeriesStatus::Active,
            'rule_version' => 1,
            'meta' => [],
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['status' => SeriesStatus::Active]);
    }

    public function paused(): self
    {
        return $this->state(fn (): array => ['status' => SeriesStatus::Paused]);
    }

    public function ended(): self
    {
        return $this->state(fn (): array => ['status' => SeriesStatus::Ended]);
    }

    /**
     * @param  list<int>  $ordinals
     */
    public function cadence(Cadence $cadence, array $ordinals = []): self
    {
        return $this->state(fn (): array => ['cadence' => $cadence, 'ordinals' => $ordinals]);
    }

    public function inZone(string $timezone): self
    {
        return $this->state(fn (): array => ['timezone' => $timezone]);
    }

    public function between(CarbonImmutable $startsOn, ?CarbonImmutable $endsOn = null): self
    {
        return $this->state(fn (): array => ['starts_on' => $startsOn, 'ends_on' => $endsOn]);
    }

    public function forContext(Model $context): self
    {
        return $this->state(fn (): array => [
            'context_type' => $context->getMorphClass(),
            'context_id' => (string) $context->getKey(),
        ]);
    }
}
