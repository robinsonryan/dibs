<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\DeleteSeries;
use RobinsonRyan\Dibs\Actions\DetachOccurrence;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Actions\FollowSeries;
use RobinsonRyan\Dibs\Actions\MaterialiseSeries;
use RobinsonRyan\Dibs\Actions\PauseSeries;
use RobinsonRyan\Dibs\Actions\RemoveOccurrenceWindow;
use RobinsonRyan\Dibs\Actions\ResumeSeries;
use RobinsonRyan\Dibs\Actions\SweepSeries;
use RobinsonRyan\Dibs\Actions\UpdateSeries;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Events\SeriesDeleted;
use RobinsonRyan\Dibs\Events\SeriesPaused;
use RobinsonRyan\Dibs\Events\SeriesResumed;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * How many of this series' times stand open and how many have stepped aside.
 *
 * @return array{open: int, retired: int}
 */
function slotStatusesOf(Series $series): array
{
    $slots = Slot::query()
        ->whereIn('availability_id', $series->occurrences()->pluck('id'))
        ->get();

    return [
        'open' => $slots->where('status', SlotStatus::Open)->count(),
        'retired' => $slots->where('status', SlotStatus::Retired)->count(),
    ];
}

it('retires every unbooked time ahead when the times are paused, and keeps the booked one', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $booking = bookFirstSlotOf($series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail());

    CarbonImmutable::setTestNow('2026-03-02 12:00:00');

    $paused = (new PauseSeries)($series);

    expect($paused->status)->toBe(SeriesStatus::Paused)
        // The four times of 2026-03-01 have already happened and are untouched;
        // of the eight ahead, the booked one stands and seven step aside.
        ->and(slotStatusesOf($series))->toBe(['open' => 5, 'retired' => 7])
        ->and($booking->fresh()?->slot?->status)->toBe(SlotStatus::Open)
        ->and(Slot::bookable()->count())->toBe(1);
});

it('retires a detached day too, because paused times offer nothing', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $detached = (new DetachOccurrence)($series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail());

    (new PauseSeries)($series);

    expect($detached->fresh()?->slots()->where('status', SlotStatus::Retired->value)->count())->toBe(4);
});

it('brings the same times back when they are opened again, without a duplicate', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $before = Slot::query()->pluck('id')->sort()->values()->all();

    (new PauseSeries)($series);
    $resumed = (new ResumeSeries)($series, CarbonImmutable::parse('2026-03-15'));

    expect($resumed->status)->toBe(SeriesStatus::Active)
        ->and(Slot::query()->pluck('id')->sort()->values()->all())->toBe($before)
        ->and(slotStatusesOf($series))->toBe(['open' => 12, 'retired' => 0]);
});

it('picks up the dates that came due while the times were paused', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    (new PauseSeries)($series);
    (new ResumeSeries)($series, CarbonImmutable::parse('2026-03-22'));

    expect($series->occurrences()->orderBy('occurs_on')->pluck('occurs_on')
        ->map(fn ($date): string => $date->format('Y-m-d'))->all())
        ->toBe(['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22']);
});

it('refuses to pause what is already paused', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new PauseSeries)($series);

    expect(fn (): Series => (new PauseSeries)($series->fresh()))->toThrow(InvalidTransition::class);
});

it('announces pausing and resuming after the transaction', function (): void {
    Event::fake([SeriesPaused::class, SeriesResumed::class]);

    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new PauseSeries)($series);
    (new ResumeSeries)($series->fresh(), CarbonImmutable::parse('2026-03-08'));

    Event::assertDispatched(SeriesPaused::class);
    Event::assertDispatched(SeriesResumed::class);
});

it('leaves a changed day out of regeneration, and folds it back in on request', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $day = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    (new DetachOccurrence)($day);

    // Move the rule: every following day is remade, this one is not.
    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    expect($day->fresh()?->id)->toBe($day->id)
        ->and($day->fresh()?->rule_version)->toBe(1);

    $followed = (new FollowSeries)($day->fresh());

    expect($followed->detached_at)->toBeNull()
        ->and($followed->id)->not->toBe($day->id)
        ->and($followed->rule_version)->toBe(2)
        ->and($followed->starts_at->setTimezone('America/Denver')->format('H:i'))->toBe('09:00');
});

it('refuses to detach a day that belongs to no series', function (): void {
    $loose = Availability::factory()->published()->create();

    expect(fn (): Availability => (new DetachOccurrence)($loose))
        ->toThrow(InvalidSeries::class);
});

it('refuses to delete times that anybody has ever booked', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    (new CancelBooking)(bookFirstSlotOf($series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail()));

    expect(fn () => (new DeleteSeries)($series))->toThrow(DeletionRefused::class)
        ->and(Series::query()->whereKey($series->id)->exists())->toBeTrue();
});

it('deletes the rule, its blocks, its pool and its days when nobody ever booked', function (): void {
    Event::fake([SeriesDeleted::class]);

    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    (new DeleteSeries)($series);

    expect(Series::query()->count())->toBe(0)
        ->and(Availability::query()->count())->toBe(0)
        ->and(Slot::query()->count())->toBe(0);

    Event::assertDispatched(SeriesDeleted::class);
});

it('rolls every open series forward, retires what has passed and ends what is over', function (): void {
    $rolling = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], horizon: 14);
    $finishing = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], endsOn: '2026-03-08', horizon: 14);

    (new SweepSeries)();

    expect($rolling->occurrences()->count())->toBe(3);

    // A fortnight on: the sweep opens the newly reachable dates, retires the
    // times that have been and gone, and ends the series whose date has passed.
    CarbonImmutable::setTestNow('2026-03-16 12:00:00');

    $created = (new SweepSeries)();

    expect($created)->toBeGreaterThan(0)
        ->and($rolling->fresh()?->status)->toBe(SeriesStatus::Active)
        ->and($rolling->occurrences()->orderBy('occurs_on')->pluck('occurs_on')
            ->map(fn ($date): string => $date->format('Y-m-d'))->all())
        ->toBe(['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22', '2026-03-29'])
        ->and($finishing->fresh()?->status)->toBe(SeriesStatus::Ended);

    $past = $rolling->occurrences()->where('occurs_on', '2026-03-01')->firstOrFail();

    expect($past->slots()->where('status', SlotStatus::Retired->value)->count())->toBe(4);
});

it('leaves a past time that somebody kept alone when it sweeps', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], horizon: 14);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $booking = bookFirstSlotOf($series->occurrences()->where('occurs_on', '2026-03-01')->firstOrFail());

    CarbonImmutable::setTestNow('2026-03-16 12:00:00');

    (new SweepSeries)();

    expect($booking->fresh()?->slot?->status)->toBe(SlotStatus::Open);
});

it('sweeps nothing into a paused series', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], horizon: 30);
    (new PauseSeries)($series);

    (new SweepSeries)();

    expect($series->occurrences()->count())->toBe(0)
        ->and($series->fresh()?->status)->toBe(SeriesStatus::Paused);
});

it('reads today on the series own clock when it ends one', function (): void {
    // 2026-03-09 00:30 UTC is still 2026-03-08 in Denver, so a series ending
    // on the 8th is not over yet on the ward's calendar.
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], endsOn: '2026-03-08', horizon: 14);

    CarbonImmutable::setTestNow('2026-03-09 00:30:00');

    (new SweepSeries)();

    expect($series->fresh()?->status)->toBe(SeriesStatus::Active);

    CarbonImmutable::setTestNow('2026-03-09 12:00:00');

    (new SweepSeries)();

    expect($series->fresh()?->status)->toBe(SeriesStatus::Ended);
});

it('still refuses to delete a rule whose used day was released by a later edit', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    (new CancelBooking)(bookFirstSlotOf($occurrence));

    // The edit cuts that day loose from the series — but the rule has plainly
    // been used, and the booking is still on the released day.
    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    expect($occurrence->fresh()?->series_id)->toBeNull()
        ->and($occurrence->fresh()?->meta['released_from_series'] ?? null)->toBe($series->id)
        ->and(fn () => (new DeleteSeries)($series))->toThrow(DeletionRefused::class)
        ->and(Series::query()->whereKey($series->id)->exists())->toBeTrue();
});

it('resumes to the series own horizon when the caller names none', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], horizon: 21);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    (new PauseSeries)($series);
    (new ResumeSeries)($series->fresh());

    // 21 days from the series' today, the same reach RegenerateSeries and
    // SweepSeries derive — the caller no longer has to know the number.
    expect($series->occurrences()->orderBy('occurs_on')->pluck('occurs_on')
        ->map(fn ($date): string => $date->format('Y-m-d'))->all())
        ->toBe(['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22'])
        ->and(slotStatusesOf($series))->toBe(['open' => 16, 'retired' => 0]);
});

it('does not put a time back on sale when its offer lapses while the series is paused', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $slot = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail()
        ->slots()->orderBy('starts_at')->firstOrFail();

    $offer = (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::parse('2026-03-03 12:00:00'));

    // Paused with the offer still out: pause leaves a held slot alone, because
    // the invitee is still deciding.
    (new PauseSeries)($series);

    expect($slot->fresh()?->status)->toBe(SlotStatus::Held);

    CarbonImmutable::setTestNow('2026-03-04 12:00:00');
    (new ExpireOffers)();

    // The offer lapsed, so the slot was let go — and a paused series offers
    // nothing, so it steps aside instead of going back on sale.
    expect($offer->fresh()?->status)->toBe(OfferStatus::Expired)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Retired)
        ->and(Slot::bookable()->pluck('id')->all())->not->toContain($slot->id)
        ->and(Slot::upcoming()->pluck('id')->all())->not->toContain($slot->id);

    // ...and resume brings that very row back, with the rest of them.
    (new ResumeSeries)($series->fresh());

    expect($slot->fresh()?->status)->toBe(SlotStatus::Open);
});

it('puts a detached day back under a paused rule without making it disappear', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $day = (new DetachOccurrence)($series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail());

    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));
    (new PauseSeries)($series->fresh());

    $followed = (new FollowSeries)($day->fresh());

    // A paused series makes nothing, so regenerating here would have deleted
    // the day and put nothing back. It is re-attached and marked stale instead.
    expect(Availability::query()->whereKey($day->id)->exists())->toBeTrue()
        ->and($followed->id)->toBe($day->id)
        ->and($followed->detached_at)->toBeNull()
        ->and($followed->rule_version)->toBe(1);

    // Resume is what remakes it, on the current rule.
    (new ResumeSeries)($series->fresh(), CarbonImmutable::parse('2026-03-15'));

    $remade = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();

    expect($remade->id)->not->toBe($day->id)
        ->and($remade->rule_version)->toBe(2)
        ->and($remade->starts_at->setTimezone('America/Denver')->format('H:i'))->toBe('09:00');
});

it('keeps a removed window empty through the sweep, a pause and a resume', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], horizon: 21);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $day = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();

    $removed = (new RemoveOccurrenceWindow)($day);

    expect($removed->status)->toBe(AvailabilityStatus::Closed)
        ->and($removed->isDetached())->toBeTrue()
        ->and($removed->slots()->where('status', SlotStatus::Open->value)->count())->toBe(0)
        ->and(Slot::upcoming()->where('availability_id', $day->id)->count())->toBe(0)
        ->and(Slot::bookable()->where('availability_id', $day->id)->count())->toBe(0);

    // The key stays occupied, so nothing lays the day down again: not the
    // sweep, not an edit, not a pause and resume.
    (new SweepSeries)();
    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));
    (new PauseSeries)($series->fresh());
    (new ResumeSeries)($series->fresh());

    expect($series->occurrences()->where('occurs_on', '2026-03-08')->count())->toBe(1)
        ->and($day->fresh()?->status)->toBe(AvailabilityStatus::Closed)
        ->and(Slot::upcoming()->where('availability_id', $day->id)->count())->toBe(0)
        ->and(Slot::bookable()->where('availability_id', $day->id)->count())->toBe(0);
});

it('refuses to remove the window of a day that belongs to no series', function (): void {
    $loose = Availability::factory()->published()->create();

    expect(fn (): Availability => (new RemoveOccurrenceWindow)($loose))
        ->toThrow(InvalidSeries::class);
});
