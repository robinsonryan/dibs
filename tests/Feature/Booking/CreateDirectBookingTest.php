<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\CreateDirectBooking;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Data\BookingOptions;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotOrigin;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\HostOverlap;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function directSpec(int $minutesAhead = 60, int $length = 45, ?string $location = 'Bishop’s office'): AdhocSlotSpec
{
    $start = CarbonImmutable::now('UTC')->addMinutes($minutesAhead);

    return new AdhocSlotSpec($start, $start->addMinutes($length), $location);
}

it('creates an adhoc slot, its booking and its assignments in one go (D4, R20)', function (): void {
    $member = user('Member');
    $clerk = user('Clerk');
    $alice = user('Alice');
    $room = room('Bishop’s office');
    $spec = directSpec();

    $booking = (new CreateDirectBooking)(
        $member,
        $clerk,
        $spec,
        [new HostAssignment($alice, 'interviewer'), new HostAssignment($room, 'room')],
    );

    $slot = $booking->slot;

    expect($booking->status)->toBe(BookingStatus::Booked)
        ->and($booking->booked_for_id)->toBe((string) $member->getKey())
        ->and($booking->booked_by_id)->toBe((string) $clerk->getKey())
        ->and($slot->origin())->toBe(SlotOrigin::Adhoc)
        ->and($slot->status)->toBe(SlotStatus::Booked)
        ->and($slot->location)->toBe('Bishop’s office')
        ->and($slot->starts_at->equalTo($spec->startsAt))->toBeTrue()
        ->and($slot->ends_at->equalTo($spec->endsAt))->toBeTrue()
        ->and($booking->hosts()->get()->map(fn (BookingHost $host): string => $host->role)->sort()->values()->all())
        ->toBe(['interviewer', 'room']);
});

it('needs no hosts at all', function (): void {
    $member = user('Member');

    $booking = (new CreateDirectBooking)($member, $member, directSpec());

    expect($booking->hosts()->count())->toBe(0);
});

it('honours the booking options', function (): void {
    $member = user('Member');

    $booking = (new CreateDirectBooking)(
        $member,
        $member,
        directSpec(),
        [],
        new BookingOptions(type: 'calling-meeting', meta: ['note' => 'walk-in']),
    );

    expect($booking->type)->toBe('calling-meeting')
        ->and($booking->fresh()->meta)->toBe(['note' => 'walk-in']);
});

it('leaves a capacity-2 adhoc slot open after one booking', function (): void {
    $member = user('Member');
    $start = CarbonImmutable::now('UTC')->addHour();

    $booking = (new CreateDirectBooking)(
        $member,
        $member,
        new AdhocSlotSpec($start, $start->addMinutes(30), null, 2),
    );

    expect($booking->slot->status)->toBe(SlotStatus::Open)
        ->and($booking->slot->capacity)->toBe(2);
});

it('guards host overlap against the supplied hosts, writing nothing', function (): void {
    $alice = user('Alice');
    $spec = directSpec();

    $clash = Slot::factory()->adhoc()->at($spec->startsAt)->create();
    $existing = Booking::factory()->for($clash, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($existing)->host($alice, 'interviewer')->create();

    $member = user('Member');

    expect(fn (): Booking => (new CreateDirectBooking)(
        $member,
        $member,
        $spec,
        [new HostAssignment($alice, 'interviewer')],
        new BookingOptions(guardHostOverlap: true),
    ))->toThrow(HostOverlap::class);

    expect(Booking::query()->count())->toBe(1)
        ->and(Slot::query()->count())->toBe(1);
});

it('refuses a spec in the past, leaving no slot behind', function (): void {
    $member = user('Member');
    $start = CarbonImmutable::now('UTC')->subHour();

    expect(fn (): Booking => (new CreateDirectBooking)(
        $member,
        $member,
        new AdhocSlotSpec($start, $start->addMinutes(30)),
    ))->toThrow(InvalidArgumentException::class);

    expect(Slot::query()->count())->toBe(0);
});

it('refuses a spec whose window does not move forward, leaving no slot behind', function (int $length): void {
    $member = user('Member');
    $start = CarbonImmutable::now('UTC')->addHour();

    expect(fn (): Booking => (new CreateDirectBooking)(
        $member,
        $member,
        new AdhocSlotSpec($start, $start->addMinutes($length)),
    ))->toThrow(InvalidArgumentException::class);

    expect(Slot::query()->count())->toBe(0)
        ->and(Booking::query()->count())->toBe(0);
})->with([0, -30]);

it('refuses a spec starting exactly now', function (): void {
    $member = user('Member');
    $now = CarbonImmutable::now('UTC');

    expect(fn (): Booking => (new CreateDirectBooking)(
        $member,
        $member,
        new AdhocSlotSpec($now, $now->addMinutes(30)),
    ))->toThrow(InvalidArgumentException::class);

    expect(Slot::query()->count())->toBe(0);
});

it('assigns a host listed twice only once (R17)', function (): void {
    $member = user('Member');
    $alice = user('Alice');

    $booking = (new CreateDirectBooking)(
        $member,
        $member,
        directSpec(),
        [new HostAssignment($alice, 'interviewer'), new HostAssignment($alice, 'interviewer')],
    );

    expect($booking->hosts()->count())->toBe(1)
        ->and($booking->hosts()->first()?->host_id)->toBe((string) $alice->getKey());
});

it('keeps one person in two roles as two assignments', function (): void {
    $member = user('Member');
    $alice = user('Alice');

    $booking = (new CreateDirectBooking)(
        $member,
        $member,
        directSpec(),
        [new HostAssignment($alice, 'interviewer'), new HostAssignment($alice, 'scribe')],
    );

    expect($booking->hosts()->orderBy('role')->pluck('role')->all())->toBe(['interviewer', 'scribe']);
});

it('stamps a supplied context on a direct booking (R40)', function (): void {
    $ward = organization('Oak Hills');
    $member = user('Member');

    $booking = (new CreateDirectBooking)($member, $member, directSpec(), [], new BookingOptions(context: $ward));

    expect($booking->context_type)->toBe('organization')
        ->and($booking->context_id)->toBe((string) $ward->getKey())
        ->and(Booking::forContext($ward)->pluck('id')->all())->toBe([$booking->id]);
});

it('leaves a direct booking without a context when none is supplied', function (): void {
    $member = user('Member');

    $booking = (new CreateDirectBooking)($member, $member, directSpec());

    expect($booking->context_type)->toBeNull()
        ->and($booking->context_id)->toBeNull();
});
