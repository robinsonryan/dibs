<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;

/**
 * @param  list<WindowSpec>  $windows
 * @param  list<HostAssignment>  $hosts
 * @param  list<int>  $ordinals
 */
function seriesSpec(
    array $windows,
    array $hosts = [],
    Cadence $cadence = Cadence::Weekly,
    array $ordinals = [],
    ?string $endsOn = null,
    int $duration = 15,
    int $padding = 5,
    ?Model $context = null,
    string $timezone = 'America/Denver',
): SeriesSpec {
    return new SeriesSpec(
        title: 'Tuesday and Thursday evenings',
        context: $context ?? organization('First Ward'),
        timezone: $timezone,
        cadence: $cadence,
        ordinals: $ordinals,
        startsOn: CarbonImmutable::parse('2026-09-06'),
        endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
        slotDurationMinutes: $duration,
        slotPaddingMinutes: $padding,
        minNoticeMinutes: 60,
        maxHorizonDays: 30,
        location: "Bishop's office",
        windows: $windows,
        hosts: $hosts === [] ? [new HostAssignment(user('Bishop'), 'interviewer')] : $hosts,
    );
}

function refuses(SeriesSpec $spec, string $reason): void
{
    try {
        $spec->ensureValid();
    } catch (InvalidSeries $invalid) {
        expect($invalid->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException('Expected the spec to be refused for '.$reason.'.');
}

it('accepts two blocks on one weekday with room for one appointment between them', function (): void {
    $spec = seriesSpec([
        new WindowSpec(2, 18 * 60, 19 * 60),
        // 20 minutes clear, and one appointment takes 15 + 5.
        new WindowSpec(2, 19 * 60 + 20, 20 * 60),
    ]);

    $spec->ensureValid();

    expect($spec->windows)->toHaveCount(2);
});

it('refuses two blocks on one weekday that overlap', function (): void {
    refuses(seriesSpec([
        new WindowSpec(2, 18 * 60, 20 * 60),
        new WindowSpec(2, 19 * 60, 21 * 60),
    ]), 'windows.overlap');
});

it('refuses two blocks with no room for an appointment between them', function (): void {
    refuses(seriesSpec([
        new WindowSpec(2, 18 * 60, 19 * 60),
        // 19 minutes clear, one short of the 15 + 5 an appointment needs.
        new WindowSpec(2, 19 * 60 + 19, 20 * 60),
    ]), 'windows.gap');
});

it('refuses two blocks that merely touch', function (): void {
    refuses(seriesSpec([
        new WindowSpec(2, 18 * 60, 19 * 60),
        new WindowSpec(2, 19 * 60, 20 * 60),
    ]), 'windows.gap');
});

it('reads blocks on different weekdays independently', function (): void {
    seriesSpec([
        new WindowSpec(2, 18 * 60, 20 * 60),
        new WindowSpec(4, 18 * 60, 20 * 60),
    ])->ensureValid();

    expect(true)->toBeTrue();
});

it('refuses a block outside the day', function (): void {
    refuses(seriesSpec([new WindowSpec(2, 18 * 60, 25 * 60)]), 'windows.bounds');
    refuses(seriesSpec([new WindowSpec(2, -30, 60)]), 'windows.bounds');
    refuses(seriesSpec([new WindowSpec(9, 18 * 60, 20 * 60)]), 'windows.bounds');
});

it('refuses a block that ends when or before it starts', function (): void {
    refuses(seriesSpec([new WindowSpec(2, 18 * 60, 18 * 60)]), 'windows.bounds');
});

it('refuses a rule with no blocks', function (): void {
    refuses(seriesSpec([]), 'windows.required');
});

it('refuses a rule with nobody to conduct', function (): void {
    $spec = new SeriesSpec(
        title: 'Evenings',
        context: organization('First Ward'),
        timezone: 'America/Denver',
        cadence: Cadence::Weekly,
        ordinals: [],
        startsOn: CarbonImmutable::parse('2026-09-06'),
        endsOn: null,
        slotDurationMinutes: 15,
        slotPaddingMinutes: 0,
        minNoticeMinutes: null,
        maxHorizonDays: null,
        location: null,
        windows: [new WindowSpec(2, 18 * 60, 20 * 60)],
        hosts: [],
    );

    refuses($spec, 'hosts.required');
});

it('refuses an end date on or before the start date', function (): void {
    refuses(seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], endsOn: '2026-09-06'), 'ends_before_starts');
    refuses(seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], endsOn: '2026-09-05'), 'ends_before_starts');
});

it('refuses a monthly rule with no ordinals and ordinals on any other cadence', function (): void {
    refuses(
        seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], cadence: Cadence::MonthlyOrdinal),
        'ordinals.required',
    );

    refuses(
        seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], cadence: Cadence::Weekly, ordinals: [1, 3]),
        'ordinals.forbidden',
    );
});

it('accepts a monthly rule that names its ordinals', function (): void {
    seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], cadence: Cadence::MonthlyOrdinal, ordinals: [1, 3])
        ->ensureValid();

    expect(true)->toBeTrue();
});

it('refuses a timezone the system does not know', function (): void {
    refuses(
        seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], timezone: 'Mars/Olympus'),
        'timezone.invalid',
    );
});

it('refuses an ordinal that is not a week of the month', function (): void {
    foreach ([0, 6, -2] as $ordinal) {
        refuses(
            seriesSpec([new WindowSpec(2, 18 * 60, 20 * 60)], cadence: Cadence::MonthlyOrdinal, ordinals: [$ordinal]),
            'ordinals.bounds',
        );
    }
});

it('names each ordinal once, in order, however the caller listed them', function (): void {
    $spec = seriesSpec(
        [new WindowSpec(2, 18 * 60, 20 * 60)],
        cadence: Cadence::MonthlyOrdinal,
        ordinals: [3, 1, 3, -1],
    );

    expect($spec->ordinals())->toBe([-1, 1, 3]);
});
