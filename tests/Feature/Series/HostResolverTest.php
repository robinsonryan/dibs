<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Dibs\Contracts\HostResolver;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\HostAvailability;
use RobinsonRyan\Dibs\Support\IdentityHostResolver;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Organization;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Room;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A stand-in for the consumer's "this calling, whoever holds it" resolution:
 * a Room resolves to the users seated in it, everything else to itself.
 *
 * @param  array<string, list<User>>  $seats
 */
function bindSeatResolver(array $seats): void
{
    app()->bind(HostResolver::class, fn (): HostResolver => new class($seats) implements HostResolver
    {
        /**
         * @param  array<string, list<User>>  $seats
         */
        public function __construct(private readonly array $seats) {}

        public function resolve(Model $host, CarbonInterface $at, ?Model $context = null): Collection
        {
            if (! $host instanceof Room) {
                return new Collection([$host]);
            }

            return new Collection($this->seats[(string) $host->getKey()] ?? []);
        }
    });
}

function slotWithPool(CarbonImmutable $startsAt, Model ...$pool): Slot
{
    $availability = Availability::factory()->published()->create();

    foreach ($pool as $member) {
        AvailabilityHost::factory()->for($availability)->host($member, 'interviewer')->create();
    }

    return Slot::factory()->for($availability)->at($startsAt, 30)->create();
}

function bookElsewhere(Model $host, CarbonImmutable $startsAt): void
{
    $slot = Slot::factory()->adhoc()->at($startsAt, 30)->create();
    $booking = Booking::factory()->for($slot, 'slot')->bookedFor(user('Member'))->create();
    BookingHost::factory()->for($booking)->host($host, 'interviewer')->create();
}

it('resolves a host to itself by default', function (): void {
    $bishop = user('Bishop');

    $resolved = app(HostResolver::class)->resolve($bishop, CarbonImmutable::now('UTC'));

    expect(app(HostResolver::class))->toBeInstanceOf(IdentityHostResolver::class)
        ->and($resolved->all())->toHaveCount(1)
        ->and($resolved->first()?->getKey())->toBe($bishop->getKey());
});

it('counts capacity as the free people the pool resolves to', function (): void {
    $counselors = room('Bishopric Counselor');
    $rob = user('Rob');
    $dan = user('Dan');
    bindSeatResolver([(string) $counselors->getKey() => [$rob, $dan]]);

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $slot = slotWithPool($at, $counselors);

    expect($slot->capacityFor())->toBe(2);
});

it('drops a resolved person who is booked across the slot', function (): void {
    $counselors = room('Bishopric Counselor');
    $rob = user('Rob');
    $dan = user('Dan');
    bindSeatResolver([(string) $counselors->getKey() => [$rob, $dan]]);

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $slot = slotWithPool($at, $counselors);

    bookElsewhere($rob, $at->addMinutes(10));

    expect($slot->capacityFor())->toBe(1)
        ->and(HostAvailability::freeHolders($slot->availability, $slot)->pluck('id')->all())
        ->toBe([$dan->getKey()]);
});

it('gives a pool that resolves to nobody no capacity and no bookable slot', function (): void {
    $counselors = room('Bishopric Counselor');
    bindSeatResolver([]);

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $slot = slotWithPool($at, $counselors);

    expect($slot->capacityFor())->toBe(0)
        ->and(Slot::bookable(null, true)->pluck('id')->all())->toBe([])
        ->and(Slot::bookable()->pluck('id')->all())->toBe([$slot->id]);
});

it('keeps a slot bookable while one of the resolved people is free', function (): void {
    $counselors = room('Bishopric Counselor');
    $rob = user('Rob');
    $dan = user('Dan');
    bindSeatResolver([(string) $counselors->getKey() => [$rob, $dan]]);

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $slot = slotWithPool($at, $counselors);

    bookElsewhere($rob, $at->addMinutes(10));

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id]);

    bookElsewhere($dan, $at->addMinutes(10));

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([]);
});

it('reads two pool callings that share a person as one person', function (): void {
    $bishopric = room('Bishopric Counselor');
    $clerks = room('Ward Clerk');
    $rob = user('Rob');
    bindSeatResolver([
        (string) $bishopric->getKey() => [$rob],
        (string) $clerks->getKey() => [$rob],
    ]);

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $slot = slotWithPool($at, $bishopric, $clerks);

    expect($slot->capacityFor())->toBe(1);
});

it('falls back to the slot capacity when there is no pool at all', function (): void {
    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $pooled = slotWithPool($at);
    $adhoc = Slot::factory()->adhoc()->at($at, 30)->capacity(3)->create();

    expect($pooled->capacityFor())->toBe(1)
        ->and($adhoc->capacityFor())->toBe(3);
});

it('resolves at the slot start unless the caller names another moment', function (): void {
    $counselors = room('Bishopric Counselor');
    $rob = user('Rob');
    $moments = new Collection;

    app()->bind(HostResolver::class, fn (): HostResolver => new class($moments, $rob) implements HostResolver
    {
        /**
         * @param  Collection<int, string>  $moments
         */
        public function __construct(private readonly Collection $moments, private readonly User $rob) {}

        public function resolve(Model $host, CarbonInterface $at, ?Model $context = null): Collection
        {
            $this->moments->push($at->toIso8601String());

            return new Collection([$this->rob]);
        }
    });

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');
    $slot = slotWithPool($at, $counselors);

    $slot->capacityFor();

    expect($moments->all())->toBe(['2026-03-08T18:00:00+00:00']);

    $slot->capacityFor(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC'));

    expect($moments->all())->toBe(['2026-03-08T18:00:00+00:00', '2026-03-01T12:00:00+00:00']);
});

it('leaves the free-host filter unchanged for a consumer with no resolver of its own', function (): void {
    $alice = user('Alice');
    $at = CarbonImmutable::parse('2026-03-08 09:00:00', 'UTC');

    $slot = slotWithPool($at, $alice);

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slot->id])
        ->and(HostAvailability::freeHosts($slot->availability, $slot, 'interviewer')->pluck('id')->all())
        ->toBe([$alice->getKey()]);

    bookElsewhere($alice, $at->addMinutes(10));

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([])
        ->and(HostAvailability::freeHosts($slot->availability->fresh(), $slot, 'interviewer')->all())->toBe([]);
});

it('resolves the same pool entry to different people for two contexts', function (): void {
    $counselors = room('Bishopric Counselor');
    $wardA = organization('Ward A');
    $wardB = organization('Ward B');
    $rob = user('Rob');
    $dan = user('Dan');
    $sam = user('Sam');

    // A calling is a catalog row shared by every ward, so its holders cannot be
    // named without the ward the availability belongs to.
    app()->bind(HostResolver::class, fn (): HostResolver => new class($wardA, [$rob, $dan], [$sam]) implements HostResolver
    {
        /**
         * @param  list<User>  $here
         * @param  list<User>  $elsewhere
         */
        public function __construct(
            private readonly Organization $wardA,
            private readonly array $here,
            private readonly array $elsewhere,
        ) {}

        public function resolve(Model $host, CarbonInterface $at, ?Model $context = null): Collection
        {
            if (! $host instanceof Room) {
                return new Collection([$host]);
            }

            return new Collection(
                $context instanceof Organization && $context->is($this->wardA) ? $this->here : $this->elsewhere,
            );
        }
    });

    $at = CarbonImmutable::parse('2026-03-08 18:00:00', 'UTC');

    $availabilityA = Availability::factory()->published()->forContext($wardA)->create();
    AvailabilityHost::factory()->for($availabilityA)->host($counselors, 'interviewer')->create();
    $slotA = Slot::factory()->for($availabilityA)->at($at, 30)->create();

    $availabilityB = Availability::factory()->published()->forContext($wardB)->create();
    AvailabilityHost::factory()->for($availabilityB)->host($counselors, 'interviewer')->create();
    $slotB = Slot::factory()->for($availabilityB)->at($at, 30)->create();

    expect($slotA->capacityFor())->toBe(2)
        ->and($slotB->capacityFor())->toBe(1)
        ->and(HostAvailability::freeHolders($availabilityA, $slotA)->pluck('id')->all())
        ->toBe([$rob->getKey(), $dan->getKey()])
        ->and(HostAvailability::freeHolders($availabilityB, $slotB)->pluck('id')->all())
        ->toBe([$sam->getKey()]);

    // The SQL free-host filter resolves the pool in PHP too, so it must see the
    // same two answers.
    bookElsewhere($sam, $at->addMinutes(10));

    expect(Slot::bookable(null, true)->pluck('id')->all())->toBe([$slotA->id]);
});
