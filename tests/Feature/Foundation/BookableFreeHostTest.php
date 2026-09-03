<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\CancelBooking;
use RobinsonRyan\Dibs\Contracts\HostResolver;
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

/**
 * @return array<int, string>
 */
function freeHostBookableIds(): array
{
    return Slot::bookable(null, true)->pluck('id')->all();
}

/**
 * @return array<int, string>
 */
function plainBookableIds(): array
{
    return Slot::bookable()->pluck('id')->all();
}

function pooledSlot(CarbonImmutable $startsAt, Model ...$hosts): Slot
{
    $availability = Availability::factory()->published()->create();

    foreach ($hosts as $host) {
        AvailabilityHost::factory()->for($availability)->host($host, 'interviewer')->create();
    }

    return Slot::factory()->for($availability)->at($startsAt, 30)->create();
}

function busyElsewhere(Model $host, CarbonImmutable $startsAt, int $minutes = 30): Booking
{
    $slot = Slot::factory()->adhoc()->at($startsAt, $minutes)->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host($host, 'interviewer')->create();

    return $booking;
}

/**
 * Binds an identity resolver that counts how often it is asked — standing in
 * for a consumer's resolver, which reads a database every time. The package's
 * own call volume is part of its cost, so it gets measured rather than assumed.
 */
function countingResolver(): object
{
    $tally = new class
    {
        public int $calls = 0;

        public function count(): int
        {
            return $this->calls;
        }

        public function reset(): void
        {
            $this->calls = 0;
        }
    };

    app()->bind(HostResolver::class, fn (): HostResolver => new class($tally) implements HostResolver
    {
        public function __construct(private readonly object $tally) {}

        public function resolve(Model $host, CarbonInterface $at, ?Model $context = null): Collection
        {
            $this->tally->calls++;

            return new Collection([$host]);
        }
    });

    return $tally;
}

it('excludes a slot whose only pool member is busy, and leaves plain bookable() alone (R47)', function (): void {
    $alice = user('Alice');
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = pooledSlot($at, $alice);
    busyElsewhere($alice, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([])
        ->and(plainBookableIds())->toContain($slot->id);
});

it('brings the slot back once the busy booking is cancelled (R47)', function (): void {
    $alice = user('Alice');
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = pooledSlot($at, $alice);
    $booking = busyElsewhere($alice, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([]);

    (new CancelBooking)($booking);

    expect(freeHostBookableIds())->toContain($slot->id);
});

it('keeps a slot whose pool of three has one busy member, and drops it when all three are (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');
    $bob = user('Bob');
    $carol = user('Carol');

    $slot = pooledSlot($at, $alice, $bob, $carol);
    busyElsewhere($bob, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toContain($slot->id);

    busyElsewhere($alice, $at->addMinutes(10), 20);
    busyElsewhere($carol, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([]);
});

it('never excludes an availability with no host pool (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = pooledSlot($at);

    expect(freeHostBookableIds())->toBe([$slot->id]);
});

it('reads each slot against its own availability’s pool (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');
    $bob = user('Bob');

    pooledSlot($at, $alice);
    $free = pooledSlot($at, $bob);
    busyElsewhere($alice, $at->addMinutes(10), 20);

    expect(freeHostBookableIds())->toBe([$free->id]);
});

it('treats the slot as half-open: a booking that ends when it starts leaves the host free (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');

    $slot = pooledSlot($at, $alice);
    busyElsewhere($alice, $at->subMinutes(30), 30);
    busyElsewhere($alice, $at->addMinutes(30), 30);

    expect(freeHostBookableIds())->toBe([$slot->id]);
});

it('does not count a pool member’s booking on the slot itself (D15)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');
    $alice = user('Alice');

    $availability = Availability::factory()->published()->create();
    AvailabilityHost::factory()->for($availability)->host($alice, 'interviewer')->create();
    $slot = Slot::factory()->for($availability)->capacity(2)->at($at, 30)->create();

    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host($alice, 'interviewer')->create();

    expect(freeHostBookableIds())->toBe([$slot->id]);
});

it('answers in a fixed number of its own queries however many slots there are (R47)', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Slot::bookable(now: null, requireFreeHost: true)->pluck('id')->all();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    // Three pooled slots, each with its member busy elsewhere, and one free.
    foreach (['Alice', 'Bob', 'Carol'] as $index => $name) {
        $host = user($name);
        pooledSlot($at->addHours($index), $host);
        busyElsewhere($host, $at->addHours($index)->addMinutes(10), 20);
    }

    $free = pooledSlot($at->addDay(), user('Dave'));

    $small = $measure();

    for ($index = 0; $index < 8; $index++) {
        pooledSlot($at->addDays(2)->addHours($index), user('Extra '.$index));
    }

    // The pool is resolved in PHP before the filter runs (a pool entry may
    // stand for somebody other than itself), so this is no longer one
    // statement — but it is a fixed handful, and tripling the slots and pools
    // does not add one. That, not the number, is what R47 is protecting.
    //
    // These are the *package's own* statements: the resolver bound here is the
    // identity one, which queries nothing. A resolver that reads a database
    // adds its own, once per distinct pooled entry per availability date —
    // measured in the next test, which is the honest bound to quote.
    expect($measure())->toBe($small)
        ->and($small)->toBeLessThanOrEqual(5);

    expect(Slot::bookable(now: null, requireFreeHost: true)->pluck('id')->all())->toContain($free->id);
});

it('asks the resolver once per pooled position per availability date, not once per pool row per slot (R47)', function (): void {
    $calls = countingResolver();

    $bishop = user('Bishop');
    $sunday = CarbonImmutable::parse('2026-03-08 15:00:00', 'UTC');

    // Two blocks on one Sunday, the same position pooled in two roles on each —
    // four pool rows — and three times in every block.
    foreach ([0, 4] as $offset) {
        $opens = $sunday->addHours($offset);
        $availability = Availability::factory()->published()->create([
            'starts_at' => $opens,
            'ends_at' => $opens->addHours(2),
        ]);

        foreach (['interviewer', 'clerk'] as $role) {
            AvailabilityHost::factory()->for($availability)->host($bishop, $role)->create();
        }

        foreach ([0, 1, 2] as $index) {
            Slot::factory()->for($availability)->at($opens->addMinutes(30 * $index), 30)->create();
        }
    }

    Slot::bookable(now: null, requireFreeHost: true)->pluck('id')->all();

    // One question — one position, one context, one date — where the pool rows
    // and the slots between them would once have made four (or eighteen).
    expect($calls->count())->toBe(1);

    $calls->reset();

    // The next Sunday is a second question: who holds a position is a fact
    // about a day, so a day is as far as one answer reaches.
    $next = Availability::factory()->published()->create([
        'starts_at' => $sunday->addWeek(),
        'ends_at' => $sunday->addWeek()->addHours(2),
    ]);
    AvailabilityHost::factory()->for($next)->host($bishop, 'interviewer')->create();
    Slot::factory()->for($next)->at($sunday->addWeek(), 30)->create();

    Slot::bookable(now: null, requireFreeHost: true)->pluck('id')->all();

    expect($calls->count())->toBe(2);
});

it('asks the resolver once for a position a slot pools twice (capacityFor)', function (): void {
    $calls = countingResolver();

    $bishop = user('Bishop');
    $availability = Availability::factory()->published()->create();

    foreach (['interviewer', 'clerk'] as $role) {
        AvailabilityHost::factory()->for($availability)->host($bishop, $role)->create();
    }

    $slot = Slot::factory()->for($availability)->at(CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC'), 30)->create();

    expect($slot->capacityFor())->toBe(1)
        ->and($calls->count())->toBe(1);
});
