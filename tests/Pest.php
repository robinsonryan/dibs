<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RobinsonRyan\Dibs\Actions\CreateSeries;
use RobinsonRyan\Dibs\Actions\CreateUnavailability;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\UnavailabilitySpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\Unavailability;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Organization;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Room;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;
use RobinsonRyan\Dibs\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

// Concurrency tests need real commits so a second connection can see the rows;
// they truncate instead of wrapping each test in a transaction.
uses(TestCase::class, DatabaseTruncation::class)->in('Concurrency');

expect()->extend('toBeUuidV7', fn () => $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'));

function user(string $name = 'Someone'): User
{
    return User::create(['name' => $name]);
}

function room(string $name = 'Room 1'): Room
{
    return Room::create(['name' => $name]);
}

function organization(string $name = 'Ward'): Organization
{
    return Organization::create(['name' => $name]);
}

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
 * The first appointment of a day, claimed by somebody.
 */
function bookFirstSlotOf(Availability $occurrence): Booking
{
    $slot = $occurrence->slots()->orderBy('starts_at')->firstOrFail();

    return Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
}

/**
 * The rule `openSeries()` opens, as a spec, so a test can change one thing
 * about it and hand it back.
 *
 * @param  list<WindowSpec>  $windows
 * @param  list<int>  $ordinals
 */
function editedSpec(
    Series $series,
    array $windows,
    ?string $title = null,
    ?Model $host = null,
    Cadence $cadence = Cadence::Weekly,
    array $ordinals = [],
    ?string $endsOn = null,
    ?int $horizon = null,
    ?array $meta = null,
    ?int $notice = null,
): SeriesSpec {
    return new SeriesSpec(
        title: $title ?? $series->title,
        context: $series->context ?? organization('First Ward'),
        timezone: $series->timezone,
        cadence: $cadence,
        ordinals: $ordinals,
        startsOn: CarbonImmutable::parse($series->starts_on->format('Y-m-d')),
        endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
        slotDurationMinutes: $series->slot_duration_minutes,
        slotPaddingMinutes: $series->slot_padding_minutes,
        minNoticeMinutes: $notice,
        maxHorizonDays: $horizon,
        location: $series->location,
        windows: $windows,
        hosts: [new HostAssignment($host ?? $series->hosts->first()?->host ?? user('Bishop'), 'interviewer')],
        meta: $meta ?? $series->meta,
    );
}

/**
 * A one-off away: a span in instants, whatever the scope's clock says.
 */
function onceSpec(
    Model $scope,
    string $startsAt = '2026-03-08 18:00:00',
    ?string $endsAt = '2026-03-08 21:00:00',
    string $timezone = 'America/Denver',
    ?string $label = null,
): UnavailabilitySpec {
    return new UnavailabilitySpec(
        scope: $scope,
        kind: UnavailabilityKind::Once,
        startsAt: CarbonImmutable::parse($startsAt, 'UTC'),
        endsAt: $endsAt === null ? null : CarbonImmutable::parse($endsAt, 'UTC'),
        timezone: $timezone,
        startsOn: null,
        endsOn: null,
        windows: [],
        label: $label,
    );
}

/**
 * A standing away: weekday windows on the scope's own clock.
 *
 * @param  list<WindowSpec>  $windows
 */
function weeklySpec(
    Model $scope,
    array $windows = [],
    string $timezone = 'America/Denver',
    string $startsOn = '2026-03-01',
    ?string $endsOn = null,
    ?string $label = null,
): UnavailabilitySpec {
    return new UnavailabilitySpec(
        scope: $scope,
        kind: UnavailabilityKind::Weekly,
        startsAt: null,
        endsAt: null,
        timezone: $timezone,
        startsOn: CarbonImmutable::parse($startsOn, 'UTC'),
        endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn, 'UTC'),
        windows: $windows === [] ? [new WindowSpec(0, 18 * 60, 19 * 60)] : $windows,
        label: $label,
    );
}

function markAway(UnavailabilitySpec $spec): Unavailability
{
    return (new CreateUnavailability)($spec);
}
