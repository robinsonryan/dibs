<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function bookableIds(): array
{
    return Slot::bookable()->pluck('id')->all();
}

it('lists published availabilities only', function (): void {
    Availability::factory()->draft()->create();
    $published = Availability::factory()->published()->create();
    Availability::factory()->closed()->create();

    expect(Availability::published()->pluck('id')->all())->toBe([$published->id]);
});

it('bookable: includes an open future slot on a published availability', function (): void {
    $slot = Slot::factory()->at(CarbonImmutable::now()->addDay())->create();

    expect(bookableIds())->toBe([$slot->id]);
});

it('bookable: excludes held and booked slots', function (): void {
    Slot::factory()->held()->at(CarbonImmutable::now()->addDay())->create();
    Slot::factory()->booked()->at(CarbonImmutable::now()->addDay())->create();

    expect(bookableIds())->toBe([]);
});

it('bookable: excludes slots on draft or closed availabilities', function (): void {
    Slot::factory()->for(Availability::factory()->draft())->at(CarbonImmutable::now()->addDay())->create();
    Slot::factory()->for(Availability::factory()->closed())->at(CarbonImmutable::now()->addDay())->create();

    expect(bookableIds())->toBe([]);
});

it('bookable: excludes past slots and adhoc slots', function (): void {
    Slot::factory()->past()->create();
    Slot::factory()->adhoc()->at(CarbonImmutable::now()->addDay())->create();

    expect(bookableIds())->toBe([]);
});

it('bookable: honours minimum notice', function (): void {
    $availability = Availability::factory()->published()->notice(120)->create();
    Slot::factory()->for($availability)->at(CarbonImmutable::now()->addMinutes(119))->create();
    $inside = Slot::factory()->for($availability)->at(CarbonImmutable::now()->addMinutes(120))->create();

    expect(bookableIds())->toBe([$inside->id]);
});

it('bookable: honours maximum horizon', function (): void {
    $availability = Availability::factory()->published()->notice(null, 7)->create();
    $inside = Slot::factory()->for($availability)->at(CarbonImmutable::now()->addDays(7))->create();
    Slot::factory()->for($availability)->at(CarbonImmutable::now()->addDays(7)->addMinute())->create();

    expect(bookableIds())->toBe([$inside->id]);
});

it('bookable: measures against a supplied instant', function (): void {
    $slot = Slot::factory()->at(CarbonImmutable::now()->addDay())->create();

    expect(Slot::bookable(CarbonImmutable::now()->addDays(2))->count())->toBe(0)
        ->and(Slot::bookable(CarbonImmutable::now()->subDays(2))->pluck('id')->all())->toBe([$slot->id]);
});

it('upcoming: any future slot regardless of status', function (): void {
    Slot::factory()->past()->create();
    $held = Slot::factory()->held()->at(CarbonImmutable::now()->addDay())->create();

    expect(Slot::upcoming()->pluck('id')->all())->toBe([$held->id]);
});

it('booking active and upcoming', function (): void {
    $future = Slot::factory()->at(CarbonImmutable::now()->addDay())->create();
    $past = Slot::factory()->past()->create();

    $active = Booking::factory()->for($future)->create();
    Booking::factory()->for($future)->cancelled()->create();
    $pastActive = Booking::factory()->for($past)->create();

    expect(Booking::active()->pluck('id')->sort()->values()->all())->toBe(collect([$active->id, $pastActive->id])->sort()->values()->all())
        ->and(Booking::upcoming()->pluck('id')->all())->toBe([$active->id]);
});

it('offer pending means pending and unexpired', function (): void {
    $open = Offer::factory()->pending()->expiresAt(null)->create();
    $future = Offer::factory()->pending()->expiresAt(CarbonImmutable::now()->addHour())->create();
    Offer::factory()->overdue()->create();
    Offer::factory()->accepted()->create();
    Offer::factory()->withdrawn()->create();

    expect(Offer::pending()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$open->id, $future->id])->sort()->values()->all());
});
