<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RobinsonRyan\Dibs\Actions\CreateSeries;
use RobinsonRyan\Dibs\Data\HostAssignment;
use RobinsonRyan\Dibs\Data\SeriesSpec;
use RobinsonRyan\Dibs\Data\WindowSpec;
use RobinsonRyan\Dibs\Enums\Cadence;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Series;
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
