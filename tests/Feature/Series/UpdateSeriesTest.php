<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Actions\ExpireOffers;
use RobinsonRyan\Dibs\Actions\FindSeriesConflicts;
use RobinsonRyan\Dibs\Actions\MaterialiseSeries;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Actions\ReparentSlotAsAdhoc;
use RobinsonRyan\Dibs\Actions\SweepSeries;
use RobinsonRyan\Dibs\Actions\UpdateSeries;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\InvalidSeries;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('bumps the rule version and remakes every future day when the hours move', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $before = $series->occurrences()->pluck('id')->all();

    $updated = (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 19 * 60, 21 * 60)], horizon: 21));

    $after = $updated->occurrences()->orderBy('occurs_on')->get();

    expect($updated->rule_version)->toBe(2)
        ->and($after->pluck('id')->intersect($before)->all())->toBe([])
        ->and($after->pluck('rule_version')->unique()->all())->toBe([2])
        ->and($after->first()?->starts_at->setTimezone('America/Denver')->format('H:i'))->toBe('19:00');
});

it('leaves the version alone when only the name and the booking window change', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $before = $series->occurrences()->pluck('id')->sort()->values()->all();

    $updated = (new UpdateSeries)($series, editedSpec(
        $series,
        [new WindowSpec(0, 18 * 60, 20 * 60)],
        title: 'Sunday evenings with the bishop',
        horizon: 45,
        meta: ['purposes' => ['temple-recommend', 'tithing-settlement']],
        notice: 120,
    ));

    $future = $updated->occurrences()->where('occurs_on', '>=', '2026-03-01')->get();

    expect($updated->rule_version)->toBe(1)
        ->and($updated->occurrences()->pluck('id')->sort()->values()->all())->toBe($before)
        ->and($future->pluck('max_horizon_days')->unique()->all())->toBe([45])
        ->and($future->pluck('min_notice_minutes')->unique()->all())->toBe([120])
        ->and($future->first()?->meta)->toBe(['purposes' => ['temple-recommend', 'tithing-settlement']]);
});

it('leaves a day carrying a live booking standing until the conflict is settled', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $booked = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $booking = bookFirstSlotOf($booked);

    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    expect($booked->fresh()?->rule_version)->toBe(1)
        ->and($booking->fresh()?->slot_id)->toBe($booking->slot_id)
        ->and($series->occurrences()->where('occurs_on', '2026-03-08')->count())->toBe(1);
});

it('releases a day whose bookings are all spent, rather than refusing to move it', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    (new CancelBooking)(bookFirstSlotOf($occurrence));

    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    $released = $occurrence->fresh();
    $remade = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();

    expect($released?->series_id)->toBeNull()
        ->and($released?->status)->toBe(AvailabilityStatus::Closed)
        ->and($remade->id)->not->toBe($occurrence->id)
        ->and($remade->rule_version)->toBe(2)
        ->and($remade->starts_at->setTimezone('America/Denver')->format('H:i'))->toBe('09:00');
});

it('names the bookings a proposed rule would strand, and only those', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $early = $occurrence->slots()->orderBy('starts_at')->firstOrFail();
    $late = $occurrence->slots()->orderByDesc('starts_at')->firstOrFail();

    $keeps = Booking::factory()->for($early, 'slot')->bookedFor(user('Stays'))->create();
    $strands = Booking::factory()->for($late, 'slot')->bookedFor(user('Stranded'))->create();

    // The evening is trimmed to 6:00–7:00: the 6 pm appointment still fits,
    // the 7:30 one no longer does.
    $conflicts = (new FindSeriesConflicts)($series, editedSpec($series, [new WindowSpec(0, 18 * 60, 19 * 60)]));

    expect($conflicts->pluck('id')->all())->toBe([$strands->id])
        ->and($conflicts->pluck('id')->all())->not->toContain($keeps->id);
});

it('counts a dropped weekday as a conflict and a shorter horizon as none', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-29'));

    $far = $series->occurrences()->where('occurs_on', '2026-03-29')->firstOrFail();
    $booking = bookFirstSlotOf($far);

    // Same hours, but only every other week: 2026-03-29 is week 4 and survives,
    // 2026-03-08 would not.
    $near = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $dropped = bookFirstSlotOf($near);

    $conflicts = (new FindSeriesConflicts)($series, editedSpec(
        $series,
        [new WindowSpec(0, 18 * 60, 20 * 60)],
        cadence: Cadence::Fortnightly,
        horizon: 7,
    ));

    expect($conflicts->pluck('id')->all())->toBe([$dropped->id])
        ->and($conflicts->pluck('id')->all())->not->toContain($booking->id);
});

it('ignores a detached day and a past booking when looking for conflicts', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $detached = $series->occurrences()->where('occurs_on', '2026-03-15')->firstOrFail();
    $detached->forceFill(['detached_at' => CarbonImmutable::now('UTC')])->save();
    bookFirstSlotOf($detached);

    $past = $series->occurrences()->where('occurs_on', '2026-03-01')->firstOrFail();
    bookFirstSlotOf($past);

    CarbonImmutable::setTestNow('2026-03-02 12:00:00');

    $conflicts = (new FindSeriesConflicts)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)]));

    expect($conflicts)->toHaveCount(0);
});

it('keeps a booking, its host and its place when its slot is cut loose', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $slot = $occurrence->slots()->orderBy('starts_at')->firstOrFail();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host(user('Bishop'), 'interviewer')->create();

    $adhoc = (new ReparentSlotAsAdhoc)($slot);

    expect($adhoc->availability_id)->toBeNull()
        ->and($adhoc->isAdhoc())->toBeTrue()
        ->and($adhoc->starts_at->equalTo($slot->starts_at))->toBeTrue()
        ->and($adhoc->ends_at->equalTo($slot->ends_at))->toBeTrue()
        ->and($adhoc->capacity)->toBe($slot->capacity)
        // The slot had no place of its own; the day's is copied onto it, or the
        // appointment would forget where it is when the day goes.
        ->and($adhoc->location)->toBe("Bishop's office")
        ->and($booking->fresh()?->slot_id)->toBe($slot->id)
        ->and($booking->hosts()->count())->toBe(1);

    // The day it came from can now be remade without taking the booking with it.
    expect(Slot::query()->whereKey($slot->id)->value('availability_id'))->toBeNull();
});

it('leaves a day standing when an offer is holding one of its times', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $offered = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    $slot = $offered->slots()->orderBy('starts_at')->firstOrFail();

    $offer = (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::parse('2026-03-06 12:00:00'));

    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    // The day, the held slot and the offer's link to it are all still there:
    // a pending offer is a live claim, exactly as a booking is.
    expect($offered->fresh()?->id)->toBe($offered->id)
        ->and($offered->fresh()?->rule_version)->toBe(1)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Held)
        ->and($offer->fresh()?->status)->toBe(OfferStatus::Pending)
        ->and($offer->fresh()?->slots()->count())->toBe(1)
        ->and($series->occurrences()->where('occurs_on', '2026-03-08')->count())->toBe(1);
});

it('catches the held day up on the next sweep, once the offer has lapsed', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)], horizon: 21);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $offered = $series->occurrences()->where('occurs_on', '2026-03-15')->firstOrFail();
    $offer = (new CreateOffer)(
        user('Invitee'),
        [$offered->slots()->orderBy('starts_at')->firstOrFail()],
        CarbonImmutable::parse('2026-03-06 12:00:00'),
    );

    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    CarbonImmutable::setTestNow('2026-03-07 12:00:00');
    (new ExpireOffers)();

    (new SweepSeries)();

    $remade = $series->occurrences()->where('occurs_on', '2026-03-15')->firstOrFail();

    expect($offer->fresh()?->status)->toBe(OfferStatus::Expired)
        ->and(Availability::query()->whereKey($offered->id)->exists())->toBeFalse()
        ->and($remade->rule_version)->toBe(2)
        ->and($remade->starts_at->setTimezone('America/Denver')->format('H:i'))->toBe('09:00');
});

it('retires the times a released day still offered, and publishing does not bring them back', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-22'));

    $occurrence = $series->occurrences()->where('occurs_on', '2026-03-08')->firstOrFail();
    (new CancelBooking)(bookFirstSlotOf($occurrence));

    (new UpdateSeries)($series, editedSpec($series, [new WindowSpec(0, 9 * 60, 11 * 60)], horizon: 21));

    $released = $occurrence->fresh();

    expect($released?->slots()->where('status', SlotStatus::Open->value)->count())->toBe(0)
        ->and($released?->slots()->where('status', SlotStatus::Retired->value)->count())->toBe(4)
        ->and(Slot::upcoming()->where('availability_id', $occurrence->id)->count())->toBe(0);

    // Reopening the released day for the sake of its history must not put the
    // old grid back beside the remade day's.
    (new PublishAvailability)($released);

    expect(Slot::bookable()->where('availability_id', $occurrence->id)->count())->toBe(0)
        ->and(Slot::upcoming()->where('availability_id', $occurrence->id)->count())->toBe(0);
});

it('refuses to move a series into another context rather than half-applying it', function (): void {
    $series = openSeries([new WindowSpec(0, 18 * 60, 20 * 60)]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-15'));

    $before = $series->context_id;

    $moved = new SeriesSpec(
        title: $series->title,
        context: organization('Second Ward'),
        timezone: $series->timezone,
        cadence: Cadence::Weekly,
        ordinals: [],
        startsOn: CarbonImmutable::parse($series->starts_on->format('Y-m-d')),
        endsOn: null,
        slotDurationMinutes: $series->slot_duration_minutes,
        slotPaddingMinutes: $series->slot_padding_minutes,
        minNoticeMinutes: null,
        maxHorizonDays: null,
        location: $series->location,
        windows: [new WindowSpec(0, 18 * 60, 20 * 60)],
        hosts: [new HostAssignment($series->hosts->first()?->host ?? user('Bishop'), 'interviewer')],
        meta: $series->meta,
    );

    try {
        (new UpdateSeries)($series, $moved);
        $this->fail('The context change was accepted.');
    } catch (InvalidSeries $refusal) {
        expect($refusal->reason)->toBe('context.immutable');
    }

    // Nothing moved: not the rule, and not one of the days it has made.
    expect($series->fresh()?->context_id)->toBe($before)
        ->and($series->occurrences()->pluck('context_id')->unique()->all())->toBe([$before]);
});

it('reports a booking that a merged block would strand, though the hours still cover it', function (): void {
    // Two blocks on Sunday evening with room for one appointment between them.
    $series = openSeries([
        new WindowSpec(0, 18 * 60, 19 * 60),
        new WindowSpec(0, 19 * 60 + 30, 20 * 60 + 30),
    ]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $second = $series->occurrences()
        ->where('occurs_on', '2026-03-08')->where('window_index', 1)->firstOrFail();
    $booking = bookFirstSlotOf($second);

    // Merged into one long block: 7:30 is still inside 6–9, so judged by time
    // alone this booking "fits" — but its block is gone, regeneration would
    // remake block 0 over the same hours and leave this day standing beside it.
    $conflicts = (new FindSeriesConflicts)($series, editedSpec($series, [new WindowSpec(0, 18 * 60, 21 * 60)]));

    expect($conflicts->pluck('id')->all())->toBe([$booking->id]);
});

it('leaves a booking alone when its block keeps its place and still covers it', function (): void {
    $series = openSeries([
        new WindowSpec(0, 18 * 60, 19 * 60),
        new WindowSpec(0, 19 * 60 + 30, 20 * 60 + 30),
    ]);
    (new MaterialiseSeries)($series, CarbonImmutable::parse('2026-03-08'));

    $second = $series->occurrences()
        ->where('occurs_on', '2026-03-08')->where('window_index', 1)->firstOrFail();
    bookFirstSlotOf($second);

    // The second block simply runs later; it is still the second block and
    // still covers 7:30.
    $conflicts = (new FindSeriesConflicts)($series, editedSpec($series, [
        new WindowSpec(0, 18 * 60, 19 * 60),
        new WindowSpec(0, 19 * 60 + 30, 21 * 60),
    ]));

    expect($conflicts)->toHaveCount(0);
});
