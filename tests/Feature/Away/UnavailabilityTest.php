<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\DeleteUnavailability;
use RobinsonRyan\Dibs\Actions\UpdateUnavailability;
use RobinsonRyan\Dibs\Data\UnavailabilitySpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Exceptions\InvalidUnavailability;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Models\UnavailabilityWindow;
use RuntimeException;

/**
 * The away is refused, for that reason and no other — the shape
 * `SeriesSpecTest` settled on, because the reason is the part a consumer keys
 * on and an exception class alone does not say which rule bit.
 */
function refusesAway(UnavailabilitySpec $spec, string $reason): void
{
    try {
        markAway($spec);
    } catch (InvalidUnavailability $invalid) {
        expect($invalid->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException('Expected the away to be refused for '.$reason.'.');
}

it('records a one-off away as a span, with no windows behind it', function (): void {
    $bishop = user('Bishop');

    $away = markAway(onceSpec($bishop, label: 'Youth conference'));

    expect($away->id)->toBeUuidV7()
        ->and($away->kind)->toBe(UnavailabilityKind::Once)
        ->and($away->scope_type)->toBe($bishop->getMorphClass())
        ->and($away->scope_id)->toBe((string) $bishop->getKey())
        ->and($away->starts_at->toIso8601String())->toBe('2026-03-08T18:00:00+00:00')
        ->and($away->ends_at->toIso8601String())->toBe('2026-03-08T21:00:00+00:00')
        ->and($away->label)->toBe('Youth conference')
        ->and($away->windows)->toHaveCount(0)
        ->and($away->scope->is($bishop))->toBeTrue();
});

it('records a standing away as weekday windows on its own clock', function (): void {
    $bishop = user('Bishop');

    $away = markAway(weeklySpec($bishop, [new WindowSpec(4, 18 * 60, 21 * 60)], endsOn: '2026-06-01'));

    expect($away->kind)->toBe(UnavailabilityKind::Weekly)
        ->and($away->starts_at)->toBeNull()
        ->and($away->timezone)->toBe('America/Denver')
        ->and($away->starts_on->format('Y-m-d'))->toBe('2026-03-01')
        ->and($away->ends_on?->format('Y-m-d'))->toBe('2026-06-01')
        ->and($away->windows)->toHaveCount(1)
        ->and($away->windows->first()?->weekday)->toBe(4)
        ->and($away->windows->first()?->starts_at_minutes)->toBe(18 * 60);
});

it('refuses a one-off away that names no span', function (): void {
    refusesAway(onceSpec(user('Bishop'), endsAt: null), InvalidUnavailability::SPAN_REQUIRED);
});

it('refuses a one-off away that ends before it starts', function (): void {
    refusesAway(
        onceSpec(user('Bishop'), startsAt: '2026-03-08 21:00:00', endsAt: '2026-03-08 18:00:00'),
        InvalidUnavailability::SPAN_INVERTED,
    );
});

it('refuses a standing away with no windows', function (): void {
    $spec = new UnavailabilitySpec(
        scope: user('Bishop'),
        kind: UnavailabilityKind::Weekly,
        startsAt: null,
        endsAt: null,
        timezone: 'America/Denver',
        startsOn: CarbonImmutable::parse('2026-03-01'),
        endsOn: null,
        windows: [],
    );

    refusesAway($spec, InvalidUnavailability::WINDOWS_REQUIRED);
});

it('refuses an away kept on a clock the system does not know', function (): void {
    refusesAway(onceSpec(user('Bishop'), timezone: 'Mars/Olympus'), InvalidUnavailability::TIMEZONE_INVALID);
});

it('refuses a standing away that ends before the day it starts', function (): void {
    refusesAway(
        weeklySpec(user('Bishop'), startsOn: '2026-03-08', endsOn: '2026-03-01'),
        InvalidUnavailability::ENDS_BEFORE_STARTS,
    );
});

it('refuses a window outside its own day', function (): void {
    refusesAway(weeklySpec(user('Bishop'), [new WindowSpec(0, 18 * 60, 25 * 60)]), InvalidUnavailability::WINDOWS_BOUNDS);
});

it('refuses windows on a one-off away, which would read one way and behave another', function (): void {
    $spec = new UnavailabilitySpec(
        scope: user('Bishop'),
        kind: UnavailabilityKind::Once,
        startsAt: CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC'),
        endsAt: CarbonImmutable::parse('2026-03-08 21:00:00', 'UTC'),
        timezone: 'America/Denver',
        startsOn: null,
        endsOn: null,
        windows: [new WindowSpec(0, 18 * 60, 19 * 60)],
    );

    refusesAway($spec, InvalidUnavailability::WINDOWS_FORBIDDEN);
});

it('covers a time a one-off away overlaps, and leaves an adjoining one alone', function (): void {
    $away = markAway(onceSpec(user('Bishop')));

    $overlapping = [
        CarbonImmutable::parse('2026-03-08 20:30:00', 'UTC'),
        CarbonImmutable::parse('2026-03-08 21:30:00', 'UTC'),
    ];
    $adjoining = [
        CarbonImmutable::parse('2026-03-08 21:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-08 21:30:00', 'UTC'),
    ];

    expect($away->covers(...$overlapping))->toBeTrue()
        ->and($away->covers(...$adjoining))->toBeFalse()
        ->and($away->covers(
            CarbonImmutable::parse('2026-03-08 17:30:00', 'UTC'),
            CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC'),
        ))->toBeFalse();
});

it('keeps a standing away at the same wall-clock hour across a daylight-saving change', function (): void {
    // Sunday 6–7 pm in Denver: 01:00 UTC while the clocks say MST, 00:00 UTC
    // once they say MDT. The rule never moved; the instants did.
    $away = markAway(weeklySpec(user('Bishop'), [new WindowSpec(0, 18 * 60, 19 * 60)]));

    $before = fn (string $at): bool => $away->covers(
        CarbonImmutable::parse($at, 'UTC'),
        CarbonImmutable::parse($at, 'UTC')->addMinutes(30),
    );

    expect($before('2026-03-02 01:00:00'))->toBeTrue()   // Sunday 1 March, 6 pm MST
        ->and($before('2026-03-02 00:00:00'))->toBeFalse()
        ->and($before('2026-03-09 00:00:00'))->toBeTrue() // Sunday 8 March, 6 pm MDT
        ->and($before('2026-03-09 01:00:00'))->toBeFalse();
});

it('covers nothing before a standing away starts or after it ends', function (): void {
    $away = markAway(weeklySpec(
        user('Bishop'),
        [new WindowSpec(0, 18 * 60, 19 * 60)],
        startsOn: '2026-03-08',
        endsOn: '2026-03-15',
    ));

    $sundayEvening = fn (string $date): bool => $away->covers(
        CarbonImmutable::parse($date.' 00:30:00', 'UTC'),
        CarbonImmutable::parse($date.' 01:00:00', 'UTC'),
    );

    expect($sundayEvening('2026-03-02'))->toBeFalse()   // the Sunday before it starts
        ->and($sundayEvening('2026-03-09'))->toBeTrue()
        ->and($sundayEvening('2026-03-16'))->toBeTrue() // the last Sunday it names
        ->and($sundayEvening('2026-03-23'))->toBeFalse();
});

it('replaces the windows of a standing away when it is edited', function (): void {
    $bishop = user('Bishop');
    $away = markAway(weeklySpec($bishop, [new WindowSpec(0, 18 * 60, 19 * 60)]));

    $edited = (new UpdateUnavailability)($away, weeklySpec($bishop, [new WindowSpec(4, 9 * 60, 10 * 60)]));

    expect($edited->windows)->toHaveCount(1)
        ->and($edited->windows->first()?->weekday)->toBe(4)
        ->and(UnavailabilityWindow::query()->count())->toBe(1);
});

it('drops the windows when a standing away becomes a one-off', function (): void {
    $bishop = user('Bishop');
    $away = markAway(weeklySpec($bishop, [new WindowSpec(0, 18 * 60, 19 * 60)]));

    $edited = (new UpdateUnavailability)($away, onceSpec($bishop));

    expect($edited->kind)->toBe(UnavailabilityKind::Once)
        ->and($edited->windows)->toHaveCount(0)
        ->and($edited->starts_on)->toBeNull()
        ->and(UnavailabilityWindow::query()->count())->toBe(0);
});

it('takes an away and its windows away for good', function (): void {
    $away = markAway(weeklySpec(user('Bishop'), [new WindowSpec(0, 18 * 60, 19 * 60)]));

    (new DeleteUnavailability)($away);

    expect(Unavailability::query()->count())->toBe(0)
        ->and(UnavailabilityWindow::query()->count())->toBe(0);
});

it('finds the aways of one scope, by shape', function (): void {
    $bishop = user('Bishop');
    $counselor = user('Counselor');

    markAway(onceSpec($bishop));
    markAway(weeklySpec($bishop, [new WindowSpec(0, 18 * 60, 19 * 60)]));
    markAway(onceSpec($counselor));

    expect(Unavailability::forScope($bishop)->count())->toBe(2)
        ->and(Unavailability::forScope($bishop)->once()->count())->toBe(1)
        ->and(Unavailability::forScope($bishop)->weekly()->count())->toBe(1)
        ->and(Unavailability::forScope($counselor)->count())->toBe(1);
});
