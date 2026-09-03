<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesWindow;

/**
 * @param  list<int>  $weekdays
 * @param  list<int>  $ordinals
 */
function ruleFrom(string $startsOn, Cadence $cadence, array $weekdays, array $ordinals = [], ?string $endsOn = null): Series
{
    $series = Series::factory()
        ->cadence($cadence, $ordinals)
        ->between(
            CarbonImmutable::parse($startsOn),
            $endsOn === null ? null : CarbonImmutable::parse($endsOn),
        )
        ->create();

    foreach ($weekdays as $weekday) {
        SeriesWindow::factory()->for($series)->on($weekday, 18 * 60, 20 * 60)->create();
    }

    return $series->load('windows');
}

/**
 * @return list<string>
 */
function datesOf(Series $series, string $from, string $through): array
{
    return array_map(
        static fn (CarbonImmutable $date): string => $date->format('Y-m-d'),
        $series->occurrenceDates(CarbonImmutable::parse($from), CarbonImmutable::parse($through)),
    );
}

it('opens every week on the weekdays it has blocks for', function (): void {
    // 2026-09-06 is a Sunday; Tuesday = 2, Thursday = 4.
    $series = ruleFrom('2026-09-06', Cadence::Weekly, [2, 4]);

    expect(datesOf($series, '2026-09-06', '2026-09-19'))
        ->toBe(['2026-09-08', '2026-09-10', '2026-09-15', '2026-09-17']);
});

it('counts Sunday-based weeks from the start week for a fortnightly rule', function (): void {
    // Starts on a Tuesday: the start week is week 0 even though it began mid-week.
    $series = ruleFrom('2026-09-08', Cadence::Fortnightly, [2]);

    expect(datesOf($series, '2026-09-01', '2026-10-10'))
        ->toBe(['2026-09-08', '2026-09-22', '2026-10-06']);
});

it('phases a fortnightly rule off the Sunday week, not off the start date', function (): void {
    // 2026-09-05 is a Saturday, the last day of the Sunday week that began
    // 2026-08-30 — so that week is week 0 and the Tuesday four days later
    // already falls in week 1 and is skipped. Counting fourteen days from the
    // start date instead would have opened 2026-09-19.
    $series = ruleFrom('2026-09-05', Cadence::Fortnightly, [2]);

    expect(datesOf($series, '2026-09-01', '2026-09-30'))
        ->toBe(['2026-09-15', '2026-09-29']);
});

it('applies ordinals to every weekday the rule has', function (): void {
    $series = ruleFrom('2026-09-01', Cadence::MonthlyOrdinal, [2, 4], [1, 3]);

    expect(datesOf($series, '2026-09-01', '2026-09-30'))
        ->toBe(['2026-09-01', '2026-09-03', '2026-09-15', '2026-09-17']);
});

it('yields nothing in a month without a fifth of that weekday', function (): void {
    $series = ruleFrom('2026-09-01', Cadence::MonthlyOrdinal, [2], [5]);

    // September 2026 has five Tuesdays (1, 8, 15, 22, 29); October has four.
    expect(datesOf($series, '2026-09-01', '2026-09-30'))->toBe(['2026-09-29'])
        ->and(datesOf($series, '2026-10-01', '2026-10-31'))->toBe([]);
});

it('reads -1 as the last of that weekday in the month, fourth or fifth', function (): void {
    $series = ruleFrom('2026-09-01', Cadence::MonthlyOrdinal, [2], [-1]);

    expect(datesOf($series, '2026-09-01', '2026-11-30'))
        ->toBe(['2026-09-29', '2026-10-27', '2026-11-24']);
});

it('opens only in the start week when it does not repeat', function (): void {
    $series = ruleFrom('2026-09-06', Cadence::Once, [2, 4]);

    expect(datesOf($series, '2026-09-06', '2026-12-31'))
        ->toBe(['2026-09-08', '2026-09-10']);
});

it('never opens before the start date or after the end date', function (): void {
    $series = ruleFrom('2026-09-15', Cadence::Weekly, [2], [], '2026-09-29');

    expect(datesOf($series, '2026-09-01', '2026-12-31'))
        ->toBe(['2026-09-15', '2026-09-22', '2026-09-29'])
        ->and($series->occursOn(CarbonImmutable::parse('2026-09-08')))->toBeFalse()
        ->and($series->occursOn(CarbonImmutable::parse('2026-10-06')))->toBeFalse();
});

it('opens nothing on a weekday it has no block for', function (): void {
    $series = ruleFrom('2026-09-06', Cadence::Weekly, [2]);

    expect($series->occursOn(CarbonImmutable::parse('2026-09-10')))->toBeFalse()
        ->and($series->occursOn(CarbonImmutable::parse('2026-09-08')))->toBeTrue();
});

it('reads a date the same whatever timezone the caller hands it in', function (): void {
    $series = ruleFrom('2026-09-06', Cadence::Weekly, [2]);

    // 2026-09-08 18:00 in Denver is 2026-09-09 00:00 UTC: the calendar date the
    // caller means is the one they wrote, not the one UTC would round it to.
    expect($series->occursOn(CarbonImmutable::create(2026, 9, 8, 18, 0, 0, 'America/Denver')))->toBeTrue();
});
