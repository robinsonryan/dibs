<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<Availability>
 */
final class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    public function modelName(): string
    {
        return Dibs::model(Availability::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now('UTC')->addDays(7)->startOfHour();

        return [
            'type' => null,
            'name' => 'Availability',
            'location' => null,
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'slot_duration_minutes' => 30,
            'slot_padding_minutes' => 0,
            'min_notice_minutes' => null,
            'max_horizon_days' => null,
            'status' => AvailabilityStatus::Draft,
            'meta' => [],
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => ['status' => AvailabilityStatus::Draft]);
    }

    /**
     * Status only — no slots are generated; use PublishAvailability for that.
     */
    public function published(): self
    {
        return $this->state(fn (): array => ['status' => AvailabilityStatus::Published]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => ['status' => AvailabilityStatus::Closed]);
    }

    public function window(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        return $this->state(fn (): array => ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    public function geometry(int $slotDurationMinutes, int $slotPaddingMinutes = 0): self
    {
        return $this->state(fn (): array => [
            'slot_duration_minutes' => $slotDurationMinutes,
            'slot_padding_minutes' => $slotPaddingMinutes,
        ]);
    }

    public function notice(?int $minNoticeMinutes, ?int $maxHorizonDays = null): self
    {
        return $this->state(fn (): array => [
            'min_notice_minutes' => $minNoticeMinutes,
            'max_horizon_days' => $maxHorizonDays,
        ]);
    }

    public function forContext(Model $context): self
    {
        return $this->state(fn (): array => [
            'context_type' => $context->getMorphClass(),
            'context_id' => (string) $context->getKey(),
        ]);
    }
}
