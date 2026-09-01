<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Exceptions\DibsException;
use RobinsonRyan\Dibs\Exceptions\InvalidGeometry;
use RobinsonRyan\Dibs\Support\SlotGrid;

function slotGridUtc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

/**
 * @param  list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $positions
 * @return list<string>
 */
function slotGridWindows(array $positions): array
{
    return array_map(
        fn (array $position): string => $position['starts_at']->format('H:i').'-'.$position['ends_at']->format('H:i'),
        $positions,
    );
}

it('places back-to-back slots when there is no padding', function (): void {
    $positions = SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 11:00'), 30));

    expect(slotGridWindows($positions))->toBe(['09:00-09:30', '09:30-10:00', '10:00-10:30', '10:30-11:00']);
});

it('advances by duration plus padding', function (): void {
    $positions = SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 11:00'), 30, 15));

    expect(slotGridWindows($positions))->toBe(['09:00-09:30', '09:45-10:15', '10:30-11:00']);
});

it('leaves a trailing remainder unused', function (): void {
    $positions = SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 10:50'), 30));

    expect(slotGridWindows($positions))->toBe(['09:00-09:30', '09:30-10:00', '10:00-10:30']);
});

it('returns an empty grid when the window is shorter than one slot', function (): void {
    $positions = SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 09:20'), 30));

    expect($positions)->toBe([]);
});

it('rejects a duration below one minute', function (int $duration): void {
    SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 11:00'), $duration));
})->with([0, -30])->throws(InvalidGeometry::class);

it('rejects negative padding', function (): void {
    SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 11:00'), 30, -5));
})->throws(InvalidGeometry::class);

it('rejects a window that does not move forward', function (string $end): void {
    SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc($end), 30));
})->with(['2026-03-01 09:00', '2026-03-01 08:00'])->throws(InvalidGeometry::class);

it('throws a dibs exception so consumers can catch one type', function (): void {
    expect(fn (): array => SlotGrid::positions(new AvailabilityGeometry(slotGridUtc('2026-03-01 09:00'), slotGridUtc('2026-03-01 11:00'), 0)))
        ->toThrow(DibsException::class);
});

it('returns UTC instants whatever offset the geometry was built in', function (): void {
    $offset = SlotGrid::positions(new AvailabilityGeometry(
        CarbonImmutable::parse('2026-03-01 02:00', '-07:00'),
        CarbonImmutable::parse('2026-03-01 04:00', '-07:00'),
        30,
    ));

    expect(slotGridWindows($offset))->toBe(['09:00-09:30', '09:30-10:00', '10:00-10:30', '10:30-11:00'])
        ->and($offset[0]['starts_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($offset[0]['starts_at']->getTimezone()->getName())->toBe('UTC')
        ->and($offset[0]['starts_at']->equalTo(slotGridUtc('2026-03-01 09:00')))->toBeTrue();
});
