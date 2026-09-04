<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Actions\FindUnavailabilityConflicts;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Models\Unavailability;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * An appointment at that time, conducted by `$host`, on a day owned by
 * `$context`.
 */
function appointmentAt(string $at, ?Model $host = null, ?Model $context = null, int $minutes = 30): Booking
{
    $availability = Availability::factory()
        ->published()
        ->when($context instanceof Model, fn ($factory) => $factory->forContext($context))
        ->create();

    $slot = Slot::factory()
        ->for($availability)
        ->at(CarbonImmutable::parse($at, 'UTC'), $minutes)
        ->booked()
        ->create();

    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();

    if ($host instanceof Model) {
        BookingHost::factory()->for($booking)->host($host, 'interviewer')->create();
    }

    return $booking;
}

/**
 * @return array<int, string>
 */
function conflictIds(mixed $away, ?CarbonImmutable $from = null): array
{
    return (new FindUnavailabilityConflicts)($away, $from)
        ->sortBy(fn (Booking $booking): string => (string) $booking->slot?->starts_at?->toIso8601String())
        ->pluck('id')
        ->values()
        ->all();
}

it('names the appointments a host’s own away falls across, and no others', function (): void {
    $bishop = user('Bishop');
    $counselor = user('Counselor');

    $inside = appointmentAt('2026-03-08 18:30:00', $bishop);
    $alsoInside = appointmentAt('2026-03-08 20:00:00', $bishop);
    appointmentAt('2026-03-08 22:00:00', $bishop);          // after the away closes
    appointmentAt('2026-03-08 18:30:00', $counselor);       // somebody else's evening
    appointmentAt('2026-03-01 18:30:00', $bishop);          // the Sunday before

    $cancelled = appointmentAt('2026-03-08 19:00:00', $bishop);
    (new CancelBooking)($cancelled);

    $away = markAway(onceSpec($bishop, '2026-03-08 18:00:00', '2026-03-08 21:00:00'));

    expect(conflictIds($away))->toBe([$inside->id, $alsoInside->id]);
});

it('names every appointment in a context when the away is the context’s own', function (): void {
    $ward = organization('First Ward');
    $otherWard = organization('Second Ward');
    $bishop = user('Bishop');
    $counselor = user('Counselor');

    $his = appointmentAt('2026-03-08 18:30:00', $bishop, $ward);
    $hers = appointmentAt('2026-03-08 19:30:00', $counselor, $ward);
    $unassigned = appointmentAt('2026-03-08 20:30:00', null, $ward);
    appointmentAt('2026-03-08 18:30:00', $bishop, $otherWard);
    appointmentAt('2026-03-08 22:00:00', $bishop, $ward);

    $away = markAway(onceSpec($ward, '2026-03-08 18:00:00', '2026-03-08 21:00:00'));

    expect(conflictIds($away))->toBe([$his->id, $hers->id, $unassigned->id]);
});

it('names an appointment booked straight onto the context, with no day behind it', function (): void {
    $ward = organization('First Ward');

    $slot = Slot::factory()->adhoc()->at(CarbonImmutable::parse('2026-03-08 18:30:00', 'UTC'), 30)->booked()->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create([
        'context_type' => $ward->getMorphClass(),
        'context_id' => (string) $ward->getKey(),
    ]);

    $away = markAway(onceSpec($ward, '2026-03-08 18:00:00', '2026-03-08 21:00:00'));

    expect(conflictIds($away))->toBe([$booking->id]);
});

it('names the appointments a standing away falls across, week after week', function (): void {
    $bishop = user('Bishop');

    // Sunday 6–7 pm in Denver, on the two Sundays either side of the clocks
    // going forward — an hour apart in UTC, both inside the rule.
    $first = appointmentAt('2026-03-02 01:30:00', $bishop);
    $second = appointmentAt('2026-03-09 00:30:00', $bishop);
    appointmentAt('2026-03-09 01:30:00', $bishop);

    $away = markAway(weeklySpec($bishop, [new WindowSpec(0, 18 * 60, 19 * 60)]));

    expect(conflictIds($away))->toBe([$first->id, $second->id]);
});

it('answers for an away that has not been recorded yet', function (): void {
    $bishop = user('Bishop');
    $inside = appointmentAt('2026-03-08 18:30:00', $bishop);
    appointmentAt('2026-03-08 22:00:00', $bishop);

    // The spec, not the row: the consumer asks before it saves, so it can put
    // the question to a person rather than cancelling on their behalf.
    expect(conflictIds(onceSpec($bishop, '2026-03-08 18:00:00', '2026-03-08 21:00:00')))->toBe([$inside->id])
        ->and(Unavailability::query()->count())->toBe(0);
});

it('looks forward only, from the moment the caller names', function (): void {
    $bishop = user('Bishop');

    // An away that opened before now: the appointment it covers in the past is
    // history, and nobody is being asked what to do about it.
    appointmentAt('2026-02-26 18:30:00', $bishop);
    $early = appointmentAt('2026-03-08 18:30:00', $bishop);
    $late = appointmentAt('2026-03-08 20:30:00', $bishop);

    $away = markAway(onceSpec($bishop, '2026-02-25 00:00:00', '2026-03-09 00:00:00'));

    expect(conflictIds($away))->toBe([$early->id, $late->id])
        ->and(conflictIds($away, CarbonImmutable::parse('2026-03-08 20:00:00', 'UTC')))->toBe([$late->id]);
});

it('says nothing when an away falls on an empty evening', function (): void {
    $bishop = user('Bishop');
    appointmentAt('2026-03-08 22:00:00', $bishop);

    $away = markAway(onceSpec($bishop, '2026-03-08 18:00:00', '2026-03-08 21:00:00'));

    expect(conflictIds($away))->toBe([]);
});
