<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Data\BookingOptions;
use RobinsonRyan\Dibs\Exceptions\HostOverlap;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('auto-assigns every role whose pool holds exactly one host (D9, R16, R17)', function (): void {
    $availability = Availability::factory()->published()->create();
    $interviewer = user('Alice');
    $room = room('Bishop’s office');

    AvailabilityHost::factory()->for($availability)->host($interviewer, 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host($room, 'room')->create();

    $slot = Slot::factory()->for($availability)->create();
    $member = user('Member');

    $booking = (new BookSlot)($slot, $member, $member);

    $assignments = $booking->hosts()->get()
        ->map(fn (BookingHost $host): array => [$host->role, $host->host_type, $host->host_id])
        ->sortBy(fn (array $row): string => $row[0])
        ->values()
        ->all();

    expect($assignments)->toBe([
        ['interviewer', 'user', (string) $interviewer->getKey()],
        ['room', 'room', (string) $room->getKey()],
    ]);
});

it('leaves a role with more than one pooled host unassigned (D9)', function (): void {
    $availability = Availability::factory()->published()->create();
    $room = room('Bishop’s office');

    AvailabilityHost::factory()->for($availability)->host(user('Alice'), 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host(user('Bob'), 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host($room, 'room')->create();

    $slot = Slot::factory()->for($availability)->create();
    $member = user('Member');

    $booking = (new BookSlot)($slot, $member, $member);

    expect($booking->hosts()->pluck('role')->all())->toBe(['room'])
        ->and($booking->hosts()->first()->host_id)->toBe((string) $room->getKey());
});

it('assigns nobody for an adhoc slot, which has no pool', function (): void {
    $slot = Slot::factory()->adhoc()->create();
    $member = user('Member');

    expect((new BookSlot)($slot, $member, $member)->hosts()->count())->toBe(0);
});

it('does not guard host overlap by default (R18)', function (): void {
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    // A pooled slot's capacity is who is free (D18), so the pool needs a member
    // nobody has spoken for, or the slot is refused before the guard is reached.
    AvailabilityHost::factory()->for($availability)->host(room('Bishop’s office'), 'room')->create();

    $slot = Slot::factory()->for($availability)->create();
    $clash = Slot::factory()->adhoc()->at($slot->starts_at)->create();
    $existing = Booking::factory()->for($clash, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($existing)->host($alice, 'interviewer')->create();

    $member = user('Member');

    // Alice is double-booked and assigned anyway: nothing asked.
    expect((new BookSlot)($slot, $member, $member)->hosts()->count())->toBe(2);
});

it('throws HostOverlap when the guard is on and an assigned host is double-booked (R18)', function (): void {
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    // A pooled slot's capacity is who is free (D18), so the pool needs a member
    // nobody has spoken for, or the slot is refused before the guard is reached.
    AvailabilityHost::factory()->for($availability)->host(room('Bishop’s office'), 'room')->create();

    $slot = Slot::factory()->for($availability)->create();
    $clash = Slot::factory()->adhoc()->at($slot->starts_at)->create();
    $existing = Booking::factory()->for($clash, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($existing)->host($alice, 'interviewer')->create();

    $member = user('Member');

    try {
        (new BookSlot)($slot, $member, $member, new BookingOptions(guardHostOverlap: true));
        $this->fail('Expected HostOverlap.');
    } catch (HostOverlap $overlap) {
        expect($overlap->host->getKey())->toBe($alice->getKey())
            ->and($overlap->overlapping->pluck('id')->all())->toBe([$existing->id]);
    }

    expect(Booking::query()->count())->toBe(1)
        ->and($slot->fresh()->status->value)->toBe('open');
});

it('books with the guard on when the assigned host is free', function (): void {
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();

    $slot = Slot::factory()->for($availability)->create();
    $elsewhere = Slot::factory()->adhoc()->at($slot->ends_at)->create();
    $existing = Booking::factory()->for($elsewhere, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($existing)->host($alice, 'interviewer')->create();

    $member = user('Member');

    expect((new BookSlot)($slot, $member, $member, new BookingOptions(guardHostOverlap: true))->hosts()->count())
        ->toBe(1);
});

it('does not count the very slot being claimed as the host’s own conflict (R19)', function (): void {
    // One interviewer and one room, both free, so the slot seats two (D18):
    // the second attendee is not clashing with the first over Alice, they are
    // sitting in the same session.
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host(room('Bishop’s office'), 'room')->create();

    $slot = Slot::factory()->for($availability)->create();

    $first = (new BookSlot)($slot->fresh(), user('Member One'), user('Member One'), new BookingOptions(guardHostOverlap: true));
    $second = (new BookSlot)($slot->fresh(), user('Member Two'), user('Member Two'), new BookingOptions(guardHostOverlap: true));

    expect($first->hosts()->count())->toBe(2)
        ->and($second->hosts()->count())->toBe(2)
        ->and(Booking::active()->count())->toBe(2)
        ->and($slot->fresh()->status->value)->toBe('booked');
});

it('still refuses when the clash is on a different overlapping slot (R19)', function (): void {
    $availability = Availability::factory()->published()->create();
    $alice = user('Alice');
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    // A pooled slot's capacity is who is free (D18), so the pool needs a member
    // nobody has spoken for, or the slot is refused before the guard is reached.
    AvailabilityHost::factory()->for($availability)->host(room('Bishop’s office'), 'room')->create();

    $slot = Slot::factory()->for($availability)->create();
    $clash = Slot::factory()->adhoc()->at($slot->starts_at)->create();
    $existing = Booking::factory()->for($clash, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($existing)->host($alice, 'interviewer')->create();

    $member = user('Member');

    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $member, $member, new BookingOptions(guardHostOverlap: true)))
        ->toThrow(HostOverlap::class)
        ->and(Booking::active()->count())->toBe(1);
});
