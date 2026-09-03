<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Actions\CreateSeries;
use RobinsonRyan\Dibs\Actions\MaterialiseSeries;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\SeriesMaterialised;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    // A Sunday, and comfortably before the spring-forward date below.
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<WindowSpec>  $windows
 * @param  list<int>  $ordinals
 */
function openSeries(
    array $windows,
    string $timezone = 'America/Denver',
    Cadence $cadence = Cadence::Weekly,
    array $ordinals = [],
    string $startsOn = '2026-03-01',
    ?string $endsOn = null,
    int $duration = 30,
    int $padding = 0,
    ?Model $host = null,
    ?Model $context = null,
    ?string $location = "Bishop's office",
    ?int $horizon = null,
): Series {
    return (new CreateSeries)(new SeriesSpec(
        title: 'Sunday evenings',
        context: $context ?? organization('First Ward'),
        timezone: $timezone,
        cadence: $cadence,
        ordinals: $ordinals,
        startsOn: CarbonImmutable::parse($startsOn),
        endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
        slotDurationMinutes: $duration,
        slotPaddingMinutes: $padding,
        minNoticeMinutes: null,
        maxHorizonDays: $horizon,
        location: $location,
        windows: $windows,
        hosts: [new HostAssignment($host ?? user('Bishop'), 'interviewer')],
        meta: ['purposes' => ['temple-recommend']],
    ));
}

/**
 * @return list<string>
 */
function occurrenceDatesOf(Series $series): array
{
    return $series->occurrences()
        ->orderBy('occurs_on')
        ->orderBy('window_index')
        ->get()
        ->map(fn (Availability $occurrence): string => $occurrence->occurs_on?->format('Y-m-d').'#'.$occurrence->window_index)
        ->all();
}

it('creates a series without materialising anything', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);

    expect($series->status->value)->toBe('active')
        ->and($series->rule_version)->toBe(1)
        ->and($series->windows)->toHaveCount(1)
        ->and($series->hosts)->toHaveCount(1)
        ->and($series->occurrences()->count())->toBe(0);
});

it('materialises one published occurrence per matching date, through the date asked for', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);

    $created = (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($created)->toBe(4)
        ->and(occurrenceDatesOf($series))
        ->toBe(['2026-03-01#0', '2026-03-08#0', '2026-03-15#0', '2026-03-22#0']);

    $first = $series->occurrences()->orderBy('occurs_on')->first();

    expect($first?->status)->toBe(AvailabilityStatus::Published)
        ->and($first?->location)->toBe("Bishop's office")
        ->and($first?->slot_duration_minutes)->toBe(30)
        ->and($first?->meta)->toBe(['purposes' => ['temple-recommend']])
        ->and($first?->rule_version)->toBe(1)
        ->and($first?->hosts()->count())->toBe(1)
        // Two hours at half an hour each.
        ->and($first?->slots()->count())->toBe(4);
});

it('keeps the same local start on every date, across both daylight-saving changes', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-11-08'));

    $locals = $series->occurrences()->orderBy('occurs_on')->get()
        ->map(fn (Availability $occurrence): string => $occurrence->starts_at->setTimezone('America/Denver')->format('H:i'))
        ->unique()
        ->values()
        ->all();

    expect($locals)->toBe(['18:00']);

    $on = fn (string $date): ?Availability => $series->occurrences()->where('occurs_on', $date)->first();

    // The wall clock holds while the offset moves: mountain standard time is
    // UTC-7 and mountain daylight time UTC-6, so the same 6 pm is stored an
    // hour apart either side of each change.
    expect($on('2026-03-01')?->starts_at->toIso8601String())->toBe('2026-03-02T01:00:00+00:00')
        ->and($on('2026-03-08')?->starts_at->toIso8601String())->toBe('2026-03-09T00:00:00+00:00')
        ->and($on('2026-10-25')?->starts_at->toIso8601String())->toBe('2026-10-26T00:00:00+00:00')
        ->and($on('2026-11-01')?->starts_at->toIso8601String())->toBe('2026-11-02T01:00:00+00:00');
});

it('creates nothing on a second run', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);

    $first = (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));
    $slots = Slot::query()->count();

    $second = (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($first)->toBe(4)
        ->and($second)->toBe(0)
        ->and($series->occurrences()->count())->toBe(4)
        ->and(Slot::query()->count())->toBe($slots);
});

it('makes two occurrences on a date with two blocks, in clock order', function (): void {
    $series = openSeries([
        new WindowSpec(0, 19 * 60, 20 * 60),
        new WindowSpec(0, 9 * 60, 11 * 60),
    ]);

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    expect(occurrenceDatesOf($series))->toBe(['2026-03-01#0', '2026-03-01#1', '2026-03-08#0', '2026-03-08#1']);

    $morning = $series->occurrences()->where('occurs_on', '2026-03-01')->where('window_index', 0)->first();

    // Block 0 is the earlier one on the clock, whatever order it was given in.
    expect($morning?->starts_at->setTimezone('America/Denver')->format('H:i'))->toBe('09:00');
});

it('never reaches back before today', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], startsOn: '2026-02-01');

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    expect(occurrenceDatesOf($series))->toBe(['2026-03-01#0', '2026-03-08#0', '2026-03-15#0']);
});

it('leaves a past occurrence and its slots exactly as they are', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    CarbonImmutable::setTestNow('2026-03-10 12:00:00');

    $past = $series->occurrences()->where('occurs_on', '2026-03-01')->firstOrFail();
    $before = $past->slots()->pluck('id')->sort()->values()->all();

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($past->fresh()?->updated_at?->equalTo($past->updated_at))->toBeTrue()
        ->and($past->slots()->pluck('id')->sort()->values()->all())->toBe($before)
        ->and(occurrenceDatesOf($series))
        ->toBe(['2026-03-01#0', '2026-03-08#0', '2026-03-15#0', '2026-03-22#0']);
});

it('leaves a booked occurrence alone', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $slot = $occurrence->slots()->orderBy('starts_at')->firstOrFail();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($booking->fresh()?->slot_id)->toBe($slot->id)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Open)
        ->and($series->occurrences()->where('occurs_on', '2026-03-08')->count())->toBe(1);
});

it('leaves a detached occurrence alone', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $occurrence->forceFill(['detached_at' => CarbonImmutable::now('UTC')])->save();
    $occurrence->slots()->delete();

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($series->occurrences()->where('occurs_on', '2026-03-08')->count())->toBe(1)
        ->and($occurrence->fresh()?->slots()->count())->toBe(0);
});

it('stops at the end date and skips dates the cadence does not want', function (): void {
    $fortnightly = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], cadence: Cadence::Fortnightly, endsOn: '2026-04-12');

    (new MaterialiseSeries)($fortnightly, CarbonImmutable::parse('2026-05-31'));

    expect(occurrenceDatesOf($fortnightly))->toBe(['2026-03-01#0', '2026-03-15#0', '2026-03-29#0', '2026-04-12#0']);
});

it('materialises nothing for a series that is not open for booking', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    $series->forceFill(['status' => 'paused'])->save();

    expect((new MaterialiseSeries)($series->fresh(), CarbonImmutable::parse('2026-03-22')))->toBe(0)
        ->and($series->occurrences()->count())->toBe(0);
});

it('announces what it made, after the transaction, and says nothing when it made nothing', function (): void {
    Event::fake([SeriesMaterialised::class]);

    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    Event::assertDispatched(SeriesMaterialised::class, fn (SeriesMaterialised $event): bool => $event->series->is($series) && $event->occurrences->count() === 2);

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    Event::assertDispatchedTimes(SeriesMaterialised::class, 1);
});

it('keeps a cancelled booking on its occurrence without remaking the day', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $slot = $occurrence->slots()->orderBy('starts_at')->firstOrFail();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    (new CancelBooking)($booking);

    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($series->occurrences()->where('occurs_on', '2026-03-08')->count())->toBe(1)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Open);
});
