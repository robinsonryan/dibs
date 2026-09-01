<?php

declare(strict_types=1);

use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\ExtendedSlot;

it('resolves the package class when nothing is configured', function (): void {
    expect(Dibs::model(Slot::class))->toBe(Slot::class)
        ->and(Dibs::make(Slot::class))->toBeInstanceOf(Slot::class);
});

it('substitutes a configured extended model in the package\'s own relationships, queries and factories', function (): void {
    config()->set('dibs.models.'.Slot::class, ExtendedSlot::class);

    $availability = Availability::factory()->create();
    $slot = Slot::factory()->for($availability)->create();
    $booking = Booking::factory()->for($slot, 'slot')->create();

    expect(Dibs::model(Slot::class))->toBe(ExtendedSlot::class)
        ->and($slot)->toBeInstanceOf(ExtendedSlot::class)
        ->and($availability->slots()->first())->toBeInstanceOf(ExtendedSlot::class)
        ->and($booking->slot()->first())->toBeInstanceOf(ExtendedSlot::class)
        ->and(Dibs::query(Slot::class)->first())->toBeInstanceOf(ExtendedSlot::class)
        ->and($availability->slots()->first()?->shout())->toBe('extended');
});

it('rejects a configured class that does not extend the package model', function (): void {
    config()->set('dibs.models.'.Slot::class, Booking::class);

    expect(fn (): string => Dibs::model(Slot::class))->toThrow(InvalidArgumentException::class);
});
