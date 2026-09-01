<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\DeleteAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Exceptions\DibsException;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function deleteAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function deletePublished(): Availability
{
    return (new PublishAvailability)(
        Availability::factory()
            ->draft()
            ->window(deleteAt('2026-03-08 09:00'), deleteAt('2026-03-08 11:00'))
            ->geometry(30)
            ->create()
    );
}

it('deletes a draft availability that never had slots', function (): void {
    $availability = Availability::factory()->draft()->create();

    (new DeleteAvailability)($availability);

    expect(Availability::query()->count())->toBe(0);
});

it('cascades open slots and the host pool', function (): void {
    $availability = deletePublished();
    AvailabilityHost::factory()->for($availability)->host(user('Bishop'), 'interviewer')->create();

    (new DeleteAvailability)($availability);

    expect(Availability::query()->count())->toBe(0)
        ->and(Slot::query()->count())->toBe(0)
        ->and(AvailabilityHost::query()->count())->toBe(0);
});

it('refuses while a slot is held and leaves everything in place', function (): void {
    $availability = deletePublished();
    $availability->slots()->orderBy('starts_at')->firstOrFail()->update(['status' => SlotStatus::Held]);

    expect(fn () => (new DeleteAvailability)($availability))->toThrow(DeletionRefused::class);

    expect(Availability::query()->count())->toBe(1)
        ->and($availability->slots()->count())->toBe(4);
});

it('refuses while a slot carries an active booking', function (): void {
    $availability = deletePublished();
    $slot = $availability->slots()->orderBy('starts_at')->firstOrFail();
    $slot->update(['status' => SlotStatus::Booked]);
    Booking::factory()->for($slot, 'slot')->bookedFor(user())->create();

    expect(fn () => (new DeleteAvailability)($availability))->toThrow(DeletionRefused::class);

    expect(Availability::query()->count())->toBe(1)
        ->and(Booking::query()->count())->toBe(1)
        ->and($availability->slots()->count())->toBe(4);
});

it('refuses while a slot carries only a cancelled booking, because bookings are history', function (): void {
    $availability = deletePublished();
    $slot = $availability->slots()->orderBy('starts_at')->firstOrFail();
    Booking::factory()->for($slot, 'slot')->bookedFor(user())->cancelled()->create();

    expect(fn () => (new DeleteAvailability)($availability))->toThrow(DeletionRefused::class);

    expect(Availability::query()->count())->toBe(1)
        ->and($availability->slots()->count())->toBe(4);
});

it('refuses with a dibs exception consumers can catch', function (): void {
    $availability = deletePublished();
    $availability->slots()->orderBy('starts_at')->firstOrFail()->update(['status' => SlotStatus::Held]);

    expect(fn () => (new DeleteAvailability)($availability))->toThrow(DibsException::class);
});

it('refuses while a slot is retired, because a retired slot still carries bookings (R41)', function (): void {
    $availability = deletePublished();
    $slot = $availability->slots()->orderBy('starts_at')->firstOrFail();
    Booking::factory()->for($slot, 'slot')->bookedFor(user())->cancelled()->create();
    $slot->update(['status' => SlotStatus::Retired]);

    expect(fn () => (new DeleteAvailability)($availability))->toThrow(DeletionRefused::class);

    expect(Availability::query()->count())->toBe(1)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Retired)
        ->and($availability->slots()->count())->toBe(4);
});
