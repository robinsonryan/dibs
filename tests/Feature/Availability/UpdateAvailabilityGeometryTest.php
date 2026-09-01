<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Actions\UpdateAvailabilityGeometry;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\AvailabilityClosed;
use RobinsonRyan\Dibs\Events\AvailabilityPublished;
use RobinsonRyan\Dibs\Exceptions\InvalidGeometry;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function geometryAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function geometryPublished(): Availability
{
    return (new PublishAvailability)(
        Availability::factory()
            ->draft()
            ->window(geometryAt('2026-03-08 09:00'), geometryAt('2026-03-08 11:00'))
            ->geometry(30)
            ->create()
    );
}

/**
 * @return Collection<int, Slot>
 */
function geometrySlots(Availability $availability): Collection
{
    // A retired slot and the fresh slot that reuses its position share a
    // starts_at, so status breaks the tie and the listing stays deterministic.
    return $availability->slots()->orderBy('starts_at')->orderBy('status')->get();
}

/**
 * @return list<string>
 */
function geometryWindows(Availability $availability): array
{
    return geometrySlots($availability)
        ->map(fn (Slot $slot): string => $slot->starts_at->format('H:i').'-'.$slot->ends_at->format('H:i').' '.$slot->status->value)
        ->all();
}

it('writes the four geometry columns', function (): void {
    $availability = Availability::factory()->draft()->create();

    $result = (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-09 13:00'),
        geometryAt('2026-03-09 15:00'),
        45,
        10,
    ));

    $stored = $result->fresh();

    expect($stored?->starts_at->toIso8601String())->toBe(geometryAt('2026-03-09 13:00')->toIso8601String())
        ->and($stored?->ends_at->toIso8601String())->toBe(geometryAt('2026-03-09 15:00')->toIso8601String())
        ->and($stored?->slot_duration_minutes)->toBe(45)
        ->and($stored?->slot_padding_minutes)->toBe(10);
});

it('generates no slots for a draft availability', function (): void {
    $availability = Availability::factory()->draft()->create();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-09 13:00'),
        geometryAt('2026-03-09 15:00'),
        30,
    ));

    expect(Slot::query()->count())->toBe(0);
});

it('regenerates the grid of a published availability', function (): void {
    $availability = geometryPublished();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 09:00'),
        geometryAt('2026-03-08 11:00'),
        60,
    ));

    expect(geometryWindows($availability))->toBe(['09:00-10:00 open', '10:00-11:00 open']);
});

it('regenerates the grid of a closed availability too', function (): void {
    $availability = geometryPublished();
    $availability->update(['status' => RobinsonRyan\Dibs\Enums\AvailabilityStatus::Closed]);

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 09:00'),
        geometryAt('2026-03-08 10:00'),
        30,
    ));

    expect(geometryWindows($availability))->toBe(['09:00-09:30 open', '09:30-10:00 open']);
});

it('never touches a held or booked slot, even outside the new window', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    $slots[2]->update(['status' => SlotStatus::Held]);
    $slots[3]->update(['status' => SlotStatus::Booked]);
    Booking::factory()->for($slots[3], 'slot')->bookedFor(user())->create();

    $heldBefore = $slots[2]->fresh();
    $bookedBefore = $slots[3]->fresh();

    // Move the clock on, so an accidental touch would stamp a different
    // updated_at and the assertions below can actually fail.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 09:00'),
        geometryAt('2026-03-08 10:00'),
        30,
    ));

    $held = $slots[2]->fresh();
    $booked = $slots[3]->fresh();

    expect($held)->not->toBeNull()
        ->and($held?->status)->toBe(SlotStatus::Held)
        ->and($held?->starts_at->toIso8601String())->toBe($heldBefore?->starts_at->toIso8601String())
        ->and($held?->ends_at->toIso8601String())->toBe($heldBefore?->ends_at->toIso8601String())
        ->and($held?->updated_at?->toIso8601String())->toBe($heldBefore?->updated_at?->toIso8601String())
        ->and($booked?->status)->toBe(SlotStatus::Booked)
        ->and($booked?->starts_at->toIso8601String())->toBe($bookedBefore?->starts_at->toIso8601String())
        ->and($booked?->updated_at?->toIso8601String())->toBe($bookedBefore?->updated_at?->toIso8601String());
});

it('retires an open slot that carries a cancelled booking rather than deleting it (R41)', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    Booking::factory()->for($slots[1], 'slot')->bookedFor(user())->cancelled()->create();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 12:00'),
        geometryAt('2026-03-08 13:00'),
        60,
    ));

    expect($slots[1]->fresh())->not->toBeNull()
        ->and($slots[1]->fresh()?->status)->toBe(SlotStatus::Retired)
        ->and($slots[0]->fresh())->toBeNull()
        ->and(geometryWindows($availability))->toBe(['09:30-10:00 retired', '12:00-13:00 open']);
});

it('keeps a retired slot’s bookings and its place on the calendar (R41)', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    $booking = Booking::factory()->for($slots[1], 'slot')->bookedFor(user())->cancelled()->create();
    $before = $slots[1]->fresh();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 12:00'),
        geometryAt('2026-03-08 13:00'),
        60,
    ));

    $retired = $slots[1]->fresh();

    expect($retired?->status)->toBe(SlotStatus::Retired)
        ->and($retired?->starts_at->toIso8601String())->toBe($before?->starts_at->toIso8601String())
        ->and($retired?->ends_at->toIso8601String())->toBe($before?->ends_at->toIso8601String())
        ->and($retired?->created_at?->toIso8601String())->toBe($before?->created_at?->toIso8601String())
        ->and($retired?->bookings()->pluck('id')->all())->toBe([$booking->id]);
});

it('leaves a retired slot out of every listing but the retired one (R41)', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    Booking::factory()->for($slots[1], 'slot')->bookedFor(user())->cancelled()->create();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 12:00'),
        geometryAt('2026-03-08 13:00'),
        60,
    ));

    expect(Slot::query()->retired()->pluck('id')->all())->toBe([$slots[1]->id])
        ->and(Slot::query()->bookable()->pluck('id')->all())->not->toContain($slots[1]->id)
        ->and(Slot::query()->upcoming()->pluck('id')->all())->not->toContain($slots[1]->id);
});

it('refuses to book a retired slot (R41)', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    Booking::factory()->for($slots[1], 'slot')->bookedFor(user())->cancelled()->create();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 12:00'),
        geometryAt('2026-03-08 13:00'),
        60,
    ));

    $retired = $slots[1]->fresh();

    expect(fn (): Booking => (new BookSlot)($retired, user('Alice'), user('Alice')))
        ->toThrow(SlotUnavailable::class);
});

it('leaves an already retired slot retired on a second regeneration (R41)', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    Booking::factory()->for($slots[1], 'slot')->bookedFor(user())->cancelled()->create();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 12:00'),
        geometryAt('2026-03-08 13:00'),
        60,
    ));

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 14:00'),
        geometryAt('2026-03-08 15:00'),
        60,
    ));

    expect($slots[1]->fresh()?->status)->toBe(SlotStatus::Retired)
        ->and(geometryWindows($availability))->toBe(['09:30-10:00 retired', '14:00-15:00 open']);
});

it('skips generated positions that overlap a surviving slot', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    $slots[2]->update(['status' => SlotStatus::Held]);
    $slots[3]->update(['status' => SlotStatus::Booked]);
    Booking::factory()->for($slots[3], 'slot')->bookedFor(user())->create();
    Booking::factory()->for($slots[1], 'slot')->bookedFor(user())->cancelled()->create();

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 09:00'),
        geometryAt('2026-03-08 10:00'),
        30,
    ));

    $regenerated = geometrySlots($availability);

    // The retired slot no longer blocks its position, so the grid lays a fresh
    // open slot over it; the held and booked ones still stand their positions.
    expect(geometryWindows($availability))->toBe([
        '09:00-09:30 open',
        '09:30-10:00 open',
        '09:30-10:00 retired',
        '10:00-10:30 held',
        '10:30-11:00 booked',
    ])
        ->and($regenerated[0]->id)->not->toBe($slots[0]->id)
        ->and($regenerated[1]->id)->not->toBe($slots[1]->id)
        ->and($regenerated[2]->id)->toBe($slots[1]->id);
});

it('regenerates around a surviving slot that only partially overlaps a position', function (): void {
    $availability = geometryPublished();
    $slots = geometrySlots($availability);
    $slots[0]->update(['status' => SlotStatus::Held, 'starts_at' => geometryAt('2026-03-08 09:10'), 'ends_at' => geometryAt('2026-03-08 09:40')]);

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 09:00'),
        geometryAt('2026-03-08 10:00'),
        30,
    ));

    expect(geometryWindows($availability))->toBe(['09:10-09:40 held']);
});

it('refuses an invalid geometry and changes nothing', function (): void {
    $availability = geometryPublished();
    $before = $availability->fresh();

    expect(fn (): Availability => (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 11:00'),
        geometryAt('2026-03-08 09:00'),
        30,
    )))->toThrow(InvalidGeometry::class);

    $after = $availability->fresh();

    expect($after?->starts_at->toIso8601String())->toBe($before?->starts_at->toIso8601String())
        ->and($after?->ends_at->toIso8601String())->toBe($before?->ends_at->toIso8601String())
        ->and($availability->slots()->count())->toBe(4);
});

it('fires no availability event', function (): void {
    $availability = geometryPublished();

    Event::fake([AvailabilityPublished::class, AvailabilityClosed::class]);

    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        geometryAt('2026-03-08 09:00'),
        geometryAt('2026-03-08 10:00'),
        30,
    ));

    Event::assertNotDispatched(AvailabilityPublished::class);
    Event::assertNotDispatched(AvailabilityClosed::class);
});
