<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;

it('carries nullable context columns on bookings and offers', function (): void {
    foreach (['dibs_bookings', 'dibs_offers'] as $table) {
        expect(Schema::hasColumns($table, ['context_type', 'context_id']))->toBeTrue($table);
    }
});

it('scopes availabilities, bookings and offers to a context', function (): void {
    $oakHills = organization('Oak Hills');
    $riverside = organization('Riverside');

    $availability = Availability::factory()->forContext($oakHills)->create();
    Availability::factory()->forContext($riverside)->create();
    Availability::factory()->create();

    $booking = Booking::factory()->forContext($oakHills)->create();
    Booking::factory()->forContext($riverside)->create();
    Booking::factory()->create();

    $offer = Offer::factory()->forContext($oakHills)->create();
    Offer::factory()->forContext($riverside)->create();
    Offer::factory()->create();

    expect(Availability::forContext($oakHills)->pluck('id')->all())->toBe([$availability->id])
        ->and(Booking::forContext($oakHills)->pluck('id')->all())->toBe([$booking->id])
        ->and(Offer::forContext($oakHills)->pluck('id')->all())->toBe([$offer->id])
        ->and($booking->context?->is($oakHills))->toBeTrue()
        ->and($offer->context?->is($oakHills))->toBeTrue()
        ->and($booking->context_type)->toBe('organization');
});
