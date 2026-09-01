<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<OfferSlot>
 */
final class OfferSlotFactory extends Factory
{
    protected $model = OfferSlot::class;

    public function modelName(): string
    {
        return Dibs::model(OfferSlot::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Dibs::model(Offer::class)::factory(),
            'slot_id' => Dibs::model(Slot::class)::factory()->held(),
        ];
    }
}
