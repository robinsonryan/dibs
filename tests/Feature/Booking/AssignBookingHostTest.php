<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Dibs\Actions\AssignBookingHost;
use RobinsonRyan\Dibs\Events\BookingHostAssigned;
use RobinsonRyan\Dibs\Exceptions\HostOverlap;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('assigns a host to a booking that had none, reporting no previous host (R43)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();
    $alice = user('Alice');

    Event::fake([BookingHostAssigned::class]);

    $returned = (new AssignBookingHost)($booking, $alice, 'interviewer');

    $rows = BookingHost::query()->where('booking_id', $booking->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->host_type)->toBe('user')
        ->and($rows->first()->host_id)->toBe((string) $alice->getKey())
        ->and($rows->first()->role)->toBe('interviewer')
        // the returned booking answers "who has it now?" without another query
        ->and($returned->relationLoaded('hosts'))->toBeTrue()
        ->and($returned->hosts->first()->relationLoaded('host'))->toBeTrue()
        ->and($returned->hosts->first()->host->getKey())->toBe($alice->getKey());

    Event::assertDispatched(
        BookingHostAssigned::class,
        fn (BookingHostAssigned $event): bool => $event->booking->is($booking)
            && $event->host->is($alice)
            && $event->role === 'interviewer'
            && ! $event->previousHost instanceof Illuminate\Database\Eloquent\Model,
    );
});

it('replaces the role’s existing host and reports the one displaced (R43, D14)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();
    $alice = user('Alice');
    $bob = user('Bob');

    BookingHost::factory()->for($booking)->host($alice, 'interviewer')->create();

    Event::fake([BookingHostAssigned::class]);

    (new AssignBookingHost)($booking, $bob, 'interviewer');

    $rows = BookingHost::query()->where('booking_id', $booking->id)->where('role', 'interviewer')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->host_id)->toBe((string) $bob->getKey());

    Event::assertDispatched(
        BookingHostAssigned::class,
        fn (BookingHostAssigned $event): bool => $event->host->is($bob)
            && $event->previousHost?->is($alice) === true,
    );
});

it('is a no-op when the same host already holds the role (R43)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();
    $alice = user('Alice');

    $existing = BookingHost::factory()->for($booking)->host($alice, 'interviewer')->create();

    Event::fake([BookingHostAssigned::class]);

    (new AssignBookingHost)($booking, $alice, 'interviewer');

    $rows = BookingHost::query()->where('booking_id', $booking->id)->get();

    // Same row, not a delete-and-reinsert: the id is the evidence.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($existing->id)
        ->and($rows->first()->updated_at?->toIso8601String())->toBe($existing->updated_at?->toIso8601String());

    Event::assertNotDispatched(BookingHostAssigned::class);
});

it('refuses to assign a host to a cancelled booking (R43, B34)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->cancelled()->create();
    $alice = user('Alice');

    Event::fake([BookingHostAssigned::class]);

    expect(fn (): Booking => (new AssignBookingHost)($booking, $alice, 'interviewer'))
        ->toThrow(InvalidTransition::class);

    expect(BookingHost::query()->count())->toBe(0);

    Event::assertNotDispatched(BookingHostAssigned::class);
});

it('allows a completed booking’s interviewer to be corrected (B34)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->completed()->create();
    $alice = user('Alice');

    (new AssignBookingHost)($booking, $alice, 'interviewer');

    expect(BookingHost::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('does not guard host overlap by default (R43)', function (): void {
    $slot = Slot::factory()->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();

    $alice = user('Alice');
    $clash = Slot::factory()->adhoc()->at($slot->starts_at)->create();
    $elsewhere = Booking::factory()->for($clash, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($elsewhere)->host($alice, 'interviewer')->create();

    (new AssignBookingHost)($booking, $alice, 'interviewer');

    expect(BookingHost::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('throws HostOverlap and writes nothing when the guard is on (R43)', function (): void {
    $slot = Slot::factory()->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();

    $alice = user('Alice');
    $bob = user('Bob');
    // Bob currently holds this booking; the reassignment to a busy Alice must not disturb him.
    $held = BookingHost::factory()->for($booking)->host($bob, 'interviewer')->create();

    $clash = Slot::factory()->adhoc()->at($slot->starts_at)->create();
    $elsewhere = Booking::factory()->for($clash, 'slot')->bookedFor(user('Someone'))->create();
    BookingHost::factory()->for($elsewhere)->host($alice, 'interviewer')->create();

    Event::fake([BookingHostAssigned::class]);

    try {
        (new AssignBookingHost)($booking, $alice, 'interviewer', guardHostOverlap: true);
        $this->fail('Expected HostOverlap.');
    } catch (HostOverlap $overlap) {
        expect($overlap->host->getKey())->toBe($alice->getKey())
            ->and($overlap->overlapping->pluck('id')->all())->toBe([$elsewhere->id]);
    }

    $rows = BookingHost::query()->where('booking_id', $booking->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($held->id)
        ->and($rows->first()->host_id)->toBe((string) $bob->getKey());

    Event::assertNotDispatched(BookingHostAssigned::class);
});

it('does not count the booking’s own slot as the host’s conflict under the guard (R19, R43)', function (): void {
    // Two attendees in one capacity-2 slot share the interviewer; taking the
    // second booking is not clashing with the first.
    $slot = Slot::factory()->capacity(2)->create();
    $first = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member One'))->create();
    $second = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member Two'))->create();

    $alice = user('Alice');
    BookingHost::factory()->for($first)->host($alice, 'interviewer')->create();

    (new AssignBookingHost)($second, $alice, 'interviewer', guardHostOverlap: true);

    expect(BookingHost::query()->where('booking_id', $second->id)->count())->toBe(1);
});

it('keeps roles independent — assigning one leaves the other alone (D7, R43)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();
    $room = room('Bishop’s office');
    $alice = user('Alice');
    $bob = user('Bob');

    (new AssignBookingHost)($booking, $room, 'room');
    (new AssignBookingHost)($booking, $alice, 'interviewer');
    (new AssignBookingHost)($booking, $bob, 'interviewer');

    $assignments = BookingHost::query()
        ->where('booking_id', $booking->id)
        ->get()
        ->map(fn (BookingHost $host): array => [$host->role, $host->host_type, $host->host_id])
        ->sortBy(fn (array $row): string => $row[0])
        ->values()
        ->all();

    expect($assignments)->toBe([
        ['interviewer', 'user', (string) $bob->getKey()],
        ['room', 'room', (string) $room->getKey()],
    ]);
});

it('defaults to the `host` role (R43)', function (): void {
    $booking = Booking::factory()->bookedFor(user('Member'))->create();

    (new AssignBookingHost)($booking, user('Alice'));

    expect(BookingHost::query()->where('booking_id', $booking->id)->first()->role)->toBe('host');
});
