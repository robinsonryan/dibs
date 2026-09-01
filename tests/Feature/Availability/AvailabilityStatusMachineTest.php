<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\CloseAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return callable(Availability): Availability
 */
function statusMachineAction(string $verb): callable
{
    return $verb === 'publish'
        ? fn (Availability $availability): Availability => (new PublishAvailability)($availability)
        : fn (Availability $availability): Availability => (new CloseAvailability)($availability);
}

it('allows exactly the three legal moves', function (AvailabilityStatus $from, string $verb, AvailabilityStatus $to): void {
    $availability = Availability::factory()->create(['status' => $from]);

    $result = statusMachineAction($verb)($availability);

    expect($result->status)->toBe($to)
        ->and($availability->fresh()?->status)->toBe($to);
})->with([
    'draft is published' => [AvailabilityStatus::Draft, 'publish', AvailabilityStatus::Published],
    'published is closed' => [AvailabilityStatus::Published, 'close', AvailabilityStatus::Closed],
    'closed is reopened' => [AvailabilityStatus::Closed, 'publish', AvailabilityStatus::Published],
]);

it('refuses every other move the two actions can be asked for', function (AvailabilityStatus $from, string $verb): void {
    $availability = Availability::factory()->create(['status' => $from]);

    expect(fn (): Availability => statusMachineAction($verb)($availability))->toThrow(InvalidTransition::class)
        ->and($availability->fresh()?->status)->toBe($from);
})->with([
    'a draft cannot be closed' => [AvailabilityStatus::Draft, 'close'],
    'a published availability cannot be published again' => [AvailabilityStatus::Published, 'publish'],
    'a closed availability cannot be closed again' => [AvailabilityStatus::Closed, 'close'],
]);

it('decides on the stored status, not a stale in-memory one', function (): void {
    $availability = Availability::factory()->create(['status' => AvailabilityStatus::Draft]);
    Availability::query()->whereKey($availability->id)->update(['status' => AvailabilityStatus::Published]);

    expect(fn (): Availability => (new PublishAvailability)($availability))->toThrow(InvalidTransition::class);
});
