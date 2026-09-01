<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Support\Dibs;

/**
 * @extends Factory<Offer>
 */
final class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function modelName(): string
    {
        return Dibs::model(Offer::class);
    }

    /**
     * Creates the offer row only — no slots are held. Use CreateOffer for the
     * real thing, or attach OfferSlot rows explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $length = config('dibs.token_length', 48);

        return [
            'token' => Str::random(is_int($length) ? $length : 48),
            'offered_to_type' => 'party',
            'offered_to_id' => (string) Str::orderedUuid(),
            'created_by_type' => null,
            'created_by_id' => null,
            'expires_at' => CarbonImmutable::now('UTC')->addDays(3),
            'status' => OfferStatus::Pending,
            'accepted_booking_id' => null,
            'message' => null,
            'meta' => [],
        ];
    }

    public function offeredTo(Model $party): self
    {
        return $this->state(fn (): array => [
            'offered_to_type' => $party->getMorphClass(),
            'offered_to_id' => (string) $party->getKey(),
        ]);
    }

    public function createdBy(Model $party): self
    {
        return $this->state(fn (): array => [
            'created_by_type' => $party->getMorphClass(),
            'created_by_id' => (string) $party->getKey(),
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => OfferStatus::Pending]);
    }

    public function accepted(): self
    {
        return $this->state(fn (): array => ['status' => OfferStatus::Accepted]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Expired,
            'expires_at' => CarbonImmutable::now('UTC')->subDay(),
        ]);
    }

    public function withdrawn(): self
    {
        return $this->state(fn (): array => ['status' => OfferStatus::Withdrawn]);
    }

    /**
     * Still `pending` but past its expiry — what a sweep or AcceptOffer must catch.
     */
    public function overdue(): self
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Pending,
            'expires_at' => CarbonImmutable::now('UTC')->subMinute(),
        ]);
    }

    public function expiresAt(?CarbonImmutable $expiresAt): self
    {
        return $this->state(fn (): array => ['expires_at' => $expiresAt]);
    }
}
