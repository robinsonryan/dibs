<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\DuplicateAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Events\AvailabilityPublished;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function duplicateAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function duplicateSource(): Availability
{
    return Availability::factory()
        ->draft()
        ->forContext(organization())
        ->window(duplicateAt('2026-03-08 09:00'), duplicateAt('2026-03-08 11:00'))
        ->geometry(30, 15)
        ->notice(120, 30)
        ->create([
            'type' => 'temple-recommend',
            'name' => 'Tuesday interviews',
            'location' => 'Bishop’s office',
            'meta' => ['note' => 'weekly'],
        ]);
}

it('copies every carried attribute into a new draft at the supplied window', function (): void {
    $source = duplicateSource();

    $copy = (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->status)->toBe(AvailabilityStatus::Draft)
        ->and($copy->starts_at->toIso8601String())->toBe(duplicateAt('2026-03-15 09:00')->toIso8601String())
        ->and($copy->ends_at->toIso8601String())->toBe(duplicateAt('2026-03-15 11:00')->toIso8601String())
        ->and($copy->type)->toBe('temple-recommend')
        ->and($copy->name)->toBe('Tuesday interviews')
        ->and($copy->location)->toBe('Bishop’s office')
        ->and($copy->slot_duration_minutes)->toBe(30)
        ->and($copy->slot_padding_minutes)->toBe(15)
        ->and($copy->min_notice_minutes)->toBe(120)
        ->and($copy->max_horizon_days)->toBe(30)
        ->and($copy->context_type)->toBe($source->context_type)
        ->and($copy->context_id)->toBe($source->context_id)
        ->and($copy->meta)->toBe(['note' => 'weekly'])
        ->and($copy->id)->toBeUuidV7();
});

it('carries every column the source has, bar the window, the status and the row identity', function (): void {
    $source = duplicateSource();

    $copy = (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    // Whatever columns an extended model adds travel with the copy, because it
    // is replicated rather than column-listed.
    $ignored = ['id', 'status', 'starts_at', 'ends_at', 'created_at', 'updated_at'];

    expect(Arr::except($copy->fresh()?->getAttributes() ?? [], $ignored))
        ->toBe(Arr::except($source->fresh()?->getAttributes() ?? [], $ignored))
        ->and(array_keys($copy->fresh()?->getAttributes() ?? []))
        ->toBe(array_keys($source->fresh()?->getAttributes() ?? []));
});

it('copies the host pool row for row', function (): void {
    $source = duplicateSource();
    $interviewer = user('Bishop');
    $venue = room('Office');
    AvailabilityHost::factory()->for($source)->host($interviewer, 'interviewer')->create();
    AvailabilityHost::factory()->for($source)->host($venue, 'room')->create();

    $copy = (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    $pool = $copy->hosts()->orderBy('role')->get()
        ->map(fn (AvailabilityHost $host): array => [$host->host_type, $host->host_id, $host->role])->all();

    expect($pool)->toBe([
        ['user', (string) $interviewer->id, 'interviewer'],
        ['room', (string) $venue->id, 'room'],
    ])
        ->and($source->hosts()->count())->toBe(2)
        ->and($copy->hosts()->pluck('id')->all())->not->toBe($source->hosts()->pluck('id')->all());
});

it('returns the copy with its pool already loaded', function (): void {
    $source = duplicateSource();
    AvailabilityHost::factory()->for($source)->host(user('Bishop'), 'interviewer')->create();

    $copy = (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    expect($copy->relationLoaded('hosts'))->toBeTrue()
        ->and($copy->hosts)->toHaveCount(1);
});

it('creates no slots and leaves the source slots alone', function (): void {
    $source = (new PublishAvailability)(duplicateSource());

    $copy = (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    expect($copy->slots()->count())->toBe(0)
        ->and($source->slots()->count())->toBe(3);
});

it('produces a draft even from a published source', function (): void {
    $source = (new PublishAvailability)(duplicateSource());

    $copy = (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    expect($copy->status)->toBe(AvailabilityStatus::Draft)
        ->and($source->fresh()?->status)->toBe(AvailabilityStatus::Published);
});

it('stores a window given in another offset as the same UTC instant', function (): void {
    $source = duplicateSource();

    $copy = (new DuplicateAvailability)(
        $source,
        CarbonImmutable::parse('2026-03-15 02:00', '-07:00'),
        CarbonImmutable::parse('2026-03-15 04:00', '-07:00'),
    );

    expect($copy->fresh()?->starts_at->toIso8601String())->toBe(duplicateAt('2026-03-15 09:00')->toIso8601String())
        ->and($copy->fresh()?->ends_at->toIso8601String())->toBe(duplicateAt('2026-03-15 11:00')->toIso8601String());
});

it('fires no event', function (): void {
    $source = duplicateSource();

    Event::fake([AvailabilityPublished::class]);

    (new DuplicateAvailability)($source, duplicateAt('2026-03-15 09:00'), duplicateAt('2026-03-15 11:00'));

    Event::assertNotDispatched(AvailabilityPublished::class);
});
