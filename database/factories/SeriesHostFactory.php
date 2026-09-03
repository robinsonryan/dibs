<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesHost;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<SeriesHost>
 */
final class SeriesHostFactory extends Factory
{
    protected $model = SeriesHost::class;

    public function modelName(): string
    {
        return Dibs::model(SeriesHost::class);
    }

    /**
     * The host morph is a placeholder until `host()` names a real consumer model.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'series_id' => Dibs::model(Series::class)::factory(),
            'host_type' => 'host',
            'host_id' => (string) Str::orderedUuid(),
            'role' => 'host',
        ];
    }

    public function host(Model $host, string $role = 'host'): self
    {
        return $this->state(fn (): array => [
            'host_type' => $host->getMorphClass(),
            'host_id' => (string) $host->getKey(),
            'role' => $role,
        ]);
    }

    public function role(string $role): self
    {
        return $this->state(fn (): array => ['role' => $role]);
    }
}
