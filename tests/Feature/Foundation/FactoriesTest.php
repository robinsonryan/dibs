<?php

declare(strict_types=1);

use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SeriesStatus;
use RobinsonRyan\Dibs\Enums\SlotOrigin;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesHost;
use RobinsonRyan\Dibs\Models\SeriesWindow;
use RobinsonRyan\Dibs\Models\Slot;

it('has an availability state for every status', function (): void {
    expect(Availability::factory()->draft()->create()->status)->toBe(AvailabilityStatus::Draft)
        ->and(Availability::factory()->published()->create()->status)->toBe(AvailabilityStatus::Published)
        ->and(Availability::factory()->closed()->create()->status)->toBe(AvailabilityStatus::Closed);
});

it('has a slot state for every status and origin', function (): void {
    expect(Slot::factory()->open()->create()->status)->toBe(SlotStatus::Open)
        ->and(Slot::factory()->held()->create()->status)->toBe(SlotStatus::Held)
        ->and(Slot::factory()->booked()->create()->status)->toBe(SlotStatus::Booked)
        ->and(Slot::factory()->retired()->create()->status)->toBe(SlotStatus::Retired)
        ->and(Slot::factory()->adhoc()->create()->origin())->toBe(SlotOrigin::Adhoc)
        ->and(Slot::factory()->create()->origin())->toBe(SlotOrigin::Availability);
});

it('has a booking state for every status', function (): void {
    $by = user();

    expect(Booking::factory()->booked()->create()->status)->toBe(BookingStatus::Booked)
        ->and(Booking::factory()->completed()->create()->status)->toBe(BookingStatus::Completed)
        ->and(Booking::factory()->noShow()->create()->status)->toBe(BookingStatus::NoShow);

    $cancelled = Booking::factory()->cancelled($by)->create();

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancelledBy?->is($by))->toBeTrue();
});

it('has an offer state for every status plus overdue', function (): void {
    expect(Offer::factory()->pending()->create()->status)->toBe(OfferStatus::Pending)
        ->and(Offer::factory()->accepted()->create()->status)->toBe(OfferStatus::Accepted)
        ->and(Offer::factory()->expired()->create()->status)->toBe(OfferStatus::Expired)
        ->and(Offer::factory()->withdrawn()->create()->status)->toBe(OfferStatus::Withdrawn);

    $overdue = Offer::factory()->overdue()->create();

    expect($overdue->status)->toBe(OfferStatus::Pending)
        ->and($overdue->isExpired())->toBeTrue()
        ->and(strlen($overdue->token))->toBeGreaterThanOrEqual(40);
});

it('sets bookedBy to bookedFor unless told otherwise', function (): void {
    $subject = user('Subject');
    $clerk = user('Clerk');

    $own = Booking::factory()->bookedFor($subject)->create();
    $proxy = Booking::factory()->bookedFor($subject)->bookedBy($clerk)->create();

    expect($own->bookedBy?->is($subject))->toBeTrue()
        ->and($proxy->bookedFor?->is($subject))->toBeTrue()
        ->and($proxy->bookedBy?->is($clerk))->toBeTrue();
});

it('has a series state for every status, and factories for its windows and pool', function (): void {
    expect(Series::factory()->active()->create()->status)->toBe(SeriesStatus::Active)
        ->and(Series::factory()->paused()->create()->status)->toBe(SeriesStatus::Paused)
        ->and(Series::factory()->ended()->create()->status)->toBe(SeriesStatus::Ended);

    $series = Series::factory()->create();
    SeriesWindow::factory()->for($series)->on(4, 9 * 60, 11 * 60)->create();
    SeriesHost::factory()->for($series)->host(user('Bishop'), 'interviewer')->create();

    expect($series->windows()->first()?->weekday)->toBe(4)
        ->and($series->hosts()->first()?->role)->toBe('interviewer')
        ->and($series->hosts()->first()?->host)->not->toBeNull();
});
