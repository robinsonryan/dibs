<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\CloseAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\AvailabilityPublished;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function publishAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function publishDraft(int $duration = 30, int $padding = 0, string $ends = '2026-03-08 11:00'): Availability
{
    return Availability::factory()
        ->draft()
        ->window(publishAt('2026-03-08 09:00'), publishAt($ends))
        ->geometry($duration, $padding)
        ->create(['location' => 'Bishop’s office']);
}

/**
 * @return list<string>
 */
function publishedWindows(Availability $availability): array
{
    return $availability->slots()
        ->orderBy('starts_at')
        ->get()
        ->map(fn (Slot $slot): string => $slot->starts_at->format('H:i').'-'.$slot->ends_at->format('H:i'))
        ->all();
}

it('moves a draft to published', function (): void {
    $availability = publishDraft();

    $result = (new PublishAvailability)($availability);

    expect($result->status)->toBe(AvailabilityStatus::Published)
        ->and($availability->fresh()?->status)->toBe(AvailabilityStatus::Published);
});

it('materialises the grid from the availability geometry', function (): void {
    $availability = publishDraft();

    (new PublishAvailability)($availability);

    expect(publishedWindows($availability))->toBe(['09:00-09:30', '09:30-10:00', '10:00-10:30', '10:30-11:00']);
});

it('honours slot padding when materialising', function (): void {
    $availability = publishDraft(30, 15);

    (new PublishAvailability)($availability);

    expect(publishedWindows($availability))->toBe(['09:00-09:30', '09:45-10:15', '10:30-11:00']);
});

it('leaves the trailing remainder of the window unused', function (): void {
    $availability = publishDraft(30, 0, '2026-03-08 10:50');

    (new PublishAvailability)($availability);

    expect(publishedWindows($availability))->toBe(['09:00-09:30', '09:30-10:00', '10:00-10:30']);
});

it('generates open capacity-one slots that carry no location of their own', function (): void {
    $availability = publishDraft();

    (new PublishAvailability)($availability);

    $slot = $availability->slots()->orderBy('starts_at')->firstOrFail();

    expect($slot->availability_id)->toBe($availability->id)
        ->and($slot->location)->toBeNull()
        ->and($slot->capacity)->toBe(1)
        ->and($slot->status)->toBe(SlotStatus::Open);
});

it('puts the generated slots into the bookable scope', function (): void {
    $availability = publishDraft();

    (new PublishAvailability)($availability);

    expect(Slot::bookable()->count())->toBe(4);
});

it('generates nothing when the availability already has slots', function (): void {
    $availability = publishDraft();
    (new PublishAvailability)($availability);
    $before = $availability->slots()->orderBy('starts_at')->pluck('id')->all();

    (new CloseAvailability)($availability);
    (new PublishAvailability)($availability);

    expect($availability->slots()->orderBy('starts_at')->pluck('id')->all())->toBe($before);
});

it('reopens a closed availability', function (): void {
    $availability = publishDraft();
    (new PublishAvailability)($availability);
    (new CloseAvailability)($availability);

    (new PublishAvailability)($availability);

    expect($availability->fresh()?->status)->toBe(AvailabilityStatus::Published);
});

it('refuses to publish an already published availability and leaves it alone', function (): void {
    $availability = publishDraft();
    (new PublishAvailability)($availability);

    expect(fn (): Availability => (new PublishAvailability)($availability))
        ->toThrow(InvalidTransition::class)
        ->and($availability->fresh()?->status)->toBe(AvailabilityStatus::Published);
});

it('rolls back the status when generation fails', function (): void {
    $availability = Availability::factory()
        ->draft()
        ->window(publishAt('2026-03-08 11:00'), publishAt('2026-03-08 09:00'))
        ->create();

    expect(fn (): Availability => (new PublishAvailability)($availability))
        ->toThrow(RobinsonRyan\Dibs\Exceptions\InvalidGeometry::class)
        ->and($availability->fresh()?->status)->toBe(AvailabilityStatus::Draft)
        ->and(Slot::query()->count())->toBe(0);
});

it('dispatches AvailabilityPublished with slots and hosts loaded', function (): void {
    $availability = publishDraft();
    $availability->hosts()->create(['host_type' => 'user', 'host_id' => (string) user()->id, 'role' => 'host']);

    Event::fake([AvailabilityPublished::class]);

    (new PublishAvailability)($availability);

    Event::assertDispatched(
        AvailabilityPublished::class,
        fn (AvailabilityPublished $event): bool => $event->availability->is($availability)
            && $event->availability->relationLoaded('slots')
            && $event->availability->slots->count() === 4
            && $event->availability->relationLoaded('hosts')
            && $event->availability->hosts->count() === 1,
    );
});

it('dispatches AvailabilityPublished only after the action commits', function (): void {
    $availability = publishDraft();
    $level = null;

    Event::listen(AvailabilityPublished::class, function () use (&$level): void {
        $level = DB::transactionLevel();
    });

    (new PublishAvailability)($availability);

    expect($level)->toBe(1);
});

it('leaves the capacity of its times to the host pool when the availability asks it to', function (): void {
    $availability = publishDraft();
    $availability->update(['capacity_from_pool' => true]);

    (new PublishAvailability)($availability);

    expect($availability->slots()->pluck('capacity')->all())->toBe([null, null, null, null]);
});
