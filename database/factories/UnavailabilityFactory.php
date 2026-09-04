<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<Unavailability>
 */
final class UnavailabilityFactory extends Factory
{
    protected $model = Unavailability::class;

    public function modelName(): string
    {
        return Dibs::model(Unavailability::class);
    }

    /**
     * A one-off away, on a placeholder scope until `for()` names a real one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_type' => 'host',
            'scope_id' => (string) Str::orderedUuid(),
            'kind' => UnavailabilityKind::Once,
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
            'ends_at' => CarbonImmutable::now('UTC')->addDay()->addHours(2),
            'timezone' => 'UTC',
        ];
    }

    public function forScope(Model $scope): self
    {
        return $this->state(fn (): array => [
            'scope_type' => $scope->getMorphClass(),
            'scope_id' => (string) $scope->getKey(),
        ]);
    }

    public function once(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        return $this->state(fn (): array => [
            'kind' => UnavailabilityKind::Once,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'starts_on' => null,
            'ends_on' => null,
        ]);
    }

    public function weekly(string $timezone, string $startsOn, ?string $endsOn = null): self
    {
        return $this->state(fn (): array => [
            'kind' => UnavailabilityKind::Weekly,
            'starts_at' => null,
            'ends_at' => null,
            'timezone' => $timezone,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    public function label(string $label): self
    {
        return $this->state(fn (): array => ['label' => $label]);
    }
}
