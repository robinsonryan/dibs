<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\CloseAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Events\AvailabilityClosed;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function closeAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function closePublished(): Availability
{
    $availability = Availability::factory()
        ->draft()
        ->window(closeAt('2026-03-08 09:00'), closeAt('2026-03-08 11:00'))
        ->geometry(30)
        ->create();

    return (new PublishAvailability)($availability);
}

it('moves a published availability to closed', function (): void {
    $availability = closePublished();

    $result = (new CloseAvailability)($availability);

    expect($result->status)->toBe(AvailabilityStatus::Closed)
        ->and($availability->fresh()?->status)->toBe(AvailabilityStatus::Closed);
});

it('leaves every slot row exactly as it was', function (): void {
    $availability = closePublished();
    $before = $availability->slots()->orderBy('starts_at')->get()
        ->map(fn (Slot $slot): array => [
            $slot->id, $slot->status->value, $slot->starts_at->toIso8601String(), $slot->ends_at->toIso8601String(), $slot->updated_at?->toIso8601String(),
        ])->all();

    (new CloseAvailability)($availability);

    $after = $availability->slots()->orderBy('starts_at')->get()
        ->map(fn (Slot $slot): array => [
            $slot->id, $slot->status->value, $slot->starts_at->toIso8601String(), $slot->ends_at->toIso8601String(), $slot->updated_at?->toIso8601String(),
        ])->all();

    expect($after)->toBe($before)->and($after)->toHaveCount(4);
});

it('drops the open slots out of the bookable scope without deleting them', function (): void {
    $availability = closePublished();
    expect(Slot::bookable()->count())->toBe(4);

    (new CloseAvailability)($availability);

    expect(Slot::bookable()->count())->toBe(0)
        ->and($availability->slots()->count())->toBe(4);
});

it('does not touch bookings on its slots', function (): void {
    $availability = closePublished();
    $slot = $availability->slots()->orderBy('starts_at')->firstOrFail();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user())->create();

    (new CloseAvailability)($availability);

    expect($booking->fresh()?->status)->toBe(BookingStatus::Booked)
        ->and($booking->fresh()?->slot_id)->toBe($slot->id);
});

it('refuses to close a draft or an already closed availability', function (string $state): void {
    $availability = Availability::factory()->{$state}()->create();

    expect(fn (): Availability => (new CloseAvailability)($availability))->toThrow(InvalidTransition::class)
        ->and($availability->fresh()?->status->value)->toBe($state);
})->with(['draft', 'closed']);

it('dispatches AvailabilityClosed with slots and hosts loaded', function (): void {
    $availability = closePublished();
    $availability->hosts()->create(['host_type' => 'user', 'host_id' => (string) user()->id, 'role' => 'host']);

    Event::fake([AvailabilityClosed::class]);

    (new CloseAvailability)($availability);

    Event::assertDispatched(
        AvailabilityClosed::class,
        fn (AvailabilityClosed $event): bool => $event->availability->is($availability)
            && $event->availability->relationLoaded('slots')
            && $event->availability->slots->count() === 4
            && $event->availability->relationLoaded('hosts')
            && $event->availability->hosts->count() === 1,
    );
});

it('dispatches AvailabilityClosed only after the action commits', function (): void {
    $availability = closePublished();
    $level = null;

    Event::listen(AvailabilityClosed::class, function () use (&$level): void {
        $level = DB::transactionLevel();
    });

    (new CloseAvailability)($availability);

    expect($level)->toBe(1);
});
