<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Actions\CompleteBooking;
use RobinsonRyan\Dibs\Actions\MarkNoShow;
use RobinsonRyan\Dibs\Events\BookingCancelled;
use RobinsonRyan\Dibs\Events\BookingCompleted;
use RobinsonRyan\Dibs\Events\BookingCreated;
use RobinsonRyan\Dibs\Events\BookingMarkedNoShow;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Records every dispatch of $event together with the transaction depth it fired
 * at. The test itself runs inside one transaction (RefreshDatabase), so depth 1
 * means the action's own transaction had already committed (R33).
 *
 * @param  class-string  $event
 */
function recordDispatches(string $event): stdClass
{
    $recorded = new stdClass;
    $recorded->events = [];
    $recorded->depths = [];

    Event::listen($event, function (object $fired) use ($recorded): void {
        $recorded->events[] = $fired;
        $recorded->depths[] = DB::transactionLevel();
    });

    return $recorded;
}

it('fires BookingCreated after commit with its relations loaded (R33)', function (): void {
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    $slot = Slot::factory()->for($availability)->create();
    $member = user('Member');

    $recorded = recordDispatches(BookingCreated::class);

    (new BookSlot)($slot, $member, $member);

    expect($recorded->depths)->toBe([1]);

    $booking = $recorded->events[0]->booking;

    expect($booking->relationLoaded('slot'))->toBeTrue()
        ->and($booking->slot->relationLoaded('availability'))->toBeTrue()
        ->and($booking->slot->availability->id)->toBe($availability->id)
        ->and($booking->relationLoaded('hosts'))->toBeTrue()
        ->and($booking->hosts)->toHaveCount(1)
        ->and($booking->relationLoaded('bookedFor'))->toBeTrue()
        ->and($booking->bookedFor->getKey())->toBe($member->getKey())
        ->and($booking->relationLoaded('bookedBy'))->toBeTrue()
        ->and($booking->bookedBy->getKey())->toBe($member->getKey());
});

it('does not fire BookingCreated when the booking is refused', function (): void {
    $slot = Slot::factory()->past()->create();
    $alice = user('Alice');

    $recorded = recordDispatches(BookingCreated::class);

    expect(fn (): Booking => (new BookSlot)($slot, $alice, $alice))->toThrow(SlotUnavailable::class)
        ->and($recorded->events)->toBe([]);
});

it('fires BookingCancelled after commit with its relations loaded (R33)', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $clerk = user('Clerk');
    $booking = (new BookSlot)($slot, $alice, $alice);

    $recorded = recordDispatches(BookingCancelled::class);

    (new CancelBooking)($booking, $clerk);

    expect($recorded->depths)->toBe([1]);

    $cancelled = $recorded->events[0]->booking;

    expect($cancelled->relationLoaded('slot'))->toBeTrue()
        ->and($cancelled->slot->status->value)->toBe('open')
        ->and($cancelled->relationLoaded('hosts'))->toBeTrue()
        ->and($cancelled->relationLoaded('bookedFor'))->toBeTrue()
        ->and($cancelled->relationLoaded('bookedBy'))->toBeTrue()
        ->and($cancelled->relationLoaded('cancelledBy'))->toBeTrue()
        ->and($cancelled->cancelledBy->getKey())->toBe($clerk->getKey());
});

it('fires BookingCompleted after commit with its relations loaded (R33)', function (): void {
    $booking = Booking::factory()->booked()->bookedFor(user('Alice'))->create();

    $recorded = recordDispatches(BookingCompleted::class);

    (new CompleteBooking)($booking);

    expect($recorded->depths)->toBe([1]);

    $completed = $recorded->events[0]->booking;

    expect($completed->relationLoaded('slot'))->toBeTrue()
        ->and($completed->relationLoaded('hosts'))->toBeTrue()
        ->and($completed->relationLoaded('bookedFor'))->toBeTrue()
        ->and($completed->relationLoaded('bookedBy'))->toBeTrue();
});

it('fires BookingMarkedNoShow after commit with its relations loaded (R33)', function (): void {
    $booking = Booking::factory()->booked()->bookedFor(user('Alice'))->create();

    $recorded = recordDispatches(BookingMarkedNoShow::class);

    (new MarkNoShow)($booking);

    expect($recorded->depths)->toBe([1]);

    $noShow = $recorded->events[0]->booking;

    expect($noShow->relationLoaded('slot'))->toBeTrue()
        ->and($noShow->relationLoaded('hosts'))->toBeTrue()
        ->and($noShow->relationLoaded('bookedFor'))->toBeTrue()
        ->and($noShow->relationLoaded('bookedBy'))->toBeTrue();
});
