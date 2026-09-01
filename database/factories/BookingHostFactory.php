<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<BookingHost>
 */
final class BookingHostFactory extends Factory
{
    protected $model = BookingHost::class;

    public function modelName(): string
    {
        return Dibs::model(BookingHost::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Dibs::model(Booking::class)::factory(),
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
