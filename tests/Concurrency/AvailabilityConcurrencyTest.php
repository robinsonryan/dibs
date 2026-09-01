<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\DeleteAvailability;
use RobinsonRyan\Dibs\Actions\PublishAvailability;
use RobinsonRyan\Dibs\Actions\UpdateAvailabilityGeometry;
use RobinsonRyan\Dibs\Data\AvailabilityGeometry;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\DeletionRefused;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\Dibs;

afterEach(function (): void {
    DB::setDefaultConnection('testing');

    foreach (['testing', 'testing_b'] as $name) {
        $connection = DB::connection($name);

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $connection->statement('set lock_timeout = 0');
        $connection->flushQueryLog();
        $connection->disableQueryLog();
    }

    // The Feature suite shares this database in the same process; these tests
    // really commit, so nothing may survive them.
    DB::connection('testing')->statement(
        'TRUNCATE dibs_booking_hosts, dibs_bookings, dibs_offer_slots, dibs_offers, '.
        'dibs_availability_hosts, dibs_slots, dibs_availabilities, '.
        'fixture_users, fixture_rooms, fixture_organizations CASCADE',
    );
});

/**
 * Every statement the connection got an answer to. A statement cancelled by the
 * lock timeout never lands here, so an empty list means the action blocked on
 * its very first query — it read nothing from around the lock.
 *
 * @return list<string>
 */
function availabilityStatements(Connection $connection): array
{
    $queries = [];

    foreach ($connection->getQueryLog() as $entry) {
        $queries[] = is_string($entry['query'] ?? null) ? $entry['query'] : '';
    }

    return $queries;
}

/**
 * Every statement the connection got an answer to that touched the slots table.
 *
 * @return list<string>
 */
function availabilitySlotStatements(Connection $connection): array
{
    return array_values(array_filter(
        availabilityStatements($connection),
        fn (string $query): bool => str_contains($query, 'dibs_slots'),
    ));
}

/**
 * Where in the log the first statement containing all of `$needles` landed, or
 * null when there is none — so a test can assert one statement ran before
 * another, not merely that both ran.
 *
 * @param  list<string>  $statements
 */
function availabilityStatementIndex(array $statements, string ...$needles): ?int
{
    foreach ($statements as $index => $statement) {
        foreach ($needles as $needle) {
            if (! str_contains($statement, $needle)) {
                continue 2;
            }
        }

        return $index;
    }

    return null;
}

it('waits for a rival transition instead of publishing over it, and writes nothing while it waits', function (): void {
    $availability = Availability::factory()
        ->draft()
        ->window(now()->addWeek()->startOfHour()->toImmutable(), now()->addWeek()->startOfHour()->addHours(2)->toImmutable())
        ->geometry(30)
        ->create();

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A is mid-transition on the very row B is about to read.
    $a->beginTransaction();
    $a->select('select id from dibs_availabilities where id = ? for update', [$availability->id]);

    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new PublishAvailability)($availability);
        $this->fail('Expected session B to block on the availability row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // It blocked on its very first statement: no status write, no slot grid.
    expect(availabilityStatements($b))->toBe([]);

    $b->disableQueryLog();

    // A publishes it after all; B's stale draft copy must not publish it twice.
    DB::setDefaultConnection('testing');
    $a->update('update dibs_availabilities set status = ? where id = ?', ['published', $availability->id]);
    $a->commit();

    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn (): Availability => (new PublishAvailability)($availability))
        ->toThrow(InvalidTransition::class);

    DB::setDefaultConnection('testing');

    expect($availability->fresh()?->status)->toBe(AvailabilityStatus::Published)
        ->and(Slot::query()->count())->toBe(0);
});

it('waits for a rival hold before deciding a deletion, and refuses once it sees it', function (): void {
    $availability = Availability::factory()
        ->draft()
        ->window(now()->addWeek()->startOfHour()->toImmutable(), now()->addWeek()->startOfHour()->addHour()->toImmutable())
        ->geometry(30)
        ->create();

    (new PublishAvailability)($availability);
    $slot = Slot::query()->where('availability_id', $availability->id)->orderBy('starts_at')->firstOrFail();

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A is taking a hold on one of the slots — uncommitted.
    $a->beginTransaction();
    $a->select('select id from dibs_slots where id = ? for update', [$slot->id]);

    // Session B is a second process: it reads the availability on its own
    // connection, as a request handler in another worker would.
    DB::setDefaultConnection('testing_b');
    $onB = Availability::query()->whereKey($availability->id)->firstOrFail();

    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new DeleteAvailability)($onB);
        $this->fail('Expected session B to block on the slot row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // It got no further than locking the availability it was asked to delete:
    // nothing was decided, nothing was written, nothing cascaded.
    expect(availabilityStatements($b))->toHaveCount(1)
        ->and(availabilityStatements($b)[0])->toContain('dibs_availabilities')
        ->and(availabilityStatements($b)[0])->toContain('for update');

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    $a->update('update dibs_slots set status = ? where id = ?', ['held', $slot->id]);
    $a->commit();

    // The hold is real now, so the delete is refused rather than cascading it away.
    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn () => (new DeleteAvailability)($onB))->toThrow(DeletionRefused::class);

    DB::setDefaultConnection('testing');

    expect(Availability::query()->count())->toBe(1)
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Held);
});

/**
 * A copy of the availability hydrated on the second connection — what a consumer
 * hands in when its own request read the row through a connection of its own.
 */
function availabilityPinnedToB(Availability $availability): Availability
{
    $query = Dibs::make(Availability::class)->setConnection('testing_b')->newQuery();

    /** @var Availability $pinned */
    $pinned = $query->findOrFail($availability->getKey());

    return $pinned;
}

function availabilityPublishedForHour(): Availability
{
    $start = now()->addWeek()->startOfHour()->toImmutable();

    $availability = Availability::factory()
        ->draft()
        ->window($start, $start->addHour())
        ->geometry(30)
        ->create();

    return (new PublishAvailability)($availability);
}

it('takes its slot lock on the transaction’s connection, not the one that hydrated the availability (R42)', function (): void {
    $availability = availabilityPublishedForHour();
    $slot = Slot::query()->where('availability_id', $availability->id)->orderBy('starts_at')->firstOrFail();
    $pinned = availabilityPinnedToB($availability);

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session B holds one of the slots. A lock, check or delete run on B's own
    // connection would sail straight through this — only work done on the
    // transaction's connection queues behind it.
    $b->beginTransaction();
    $b->select('select id from dibs_slots where id = ? for update', [$slot->id]);

    $a->statement("set lock_timeout = '300ms'");
    $a->flushQueryLog();
    $a->enableQueryLog();
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new DeleteAvailability)($pinned);
        $this->fail('Expected the delete to block on the default connection, not run inside session B.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // Not one statement ran on the connection that hydrated the availability.
    // Reached through the relation instead of the class-map, the slot lock would
    // have run inside session B's own transaction and sailed straight through
    // the lock it already holds — and the 55P03 above would have come from the
    // cascade at the end, not from the lock at the start.
    expect(availabilityStatements($b))->toBe([])
        // On the transaction's own connection it got no further than the
        // availability lock: the slot lock is the statement the timeout killed,
        // and a cancelled statement never reaches the log.
        ->and(availabilityStatements($a))->toHaveCount(1)
        ->and(availabilityStatements($a)[0])->toContain('dibs_availabilities')
        ->and(availabilityStatements($a)[0])->toContain('for update');

    $a->disableQueryLog();
    $b->disableQueryLog();
    $a->statement('set lock_timeout = 0');
    $b->rollBack();

    // Nothing was decided or cascaded from the far side of the lock.
    expect(Availability::query()->count())->toBe(1)
        ->and(Slot::query()->count())->toBe(2);

    // With the rival gone the delete goes through, and the order of its
    // statements is the guarantee: the slots are locked before the availability
    // row is deleted, so the cascade is never what discovers a rival hold.
    $a->flushQueryLog();
    $a->enableQueryLog();

    (new DeleteAvailability)($pinned);

    $statements = availabilityStatements($a);
    $lockedSlots = availabilityStatementIndex($statements, 'dibs_slots', 'for update');
    $deletedAvailability = availabilityStatementIndex($statements, 'delete from', 'dibs_availabilities');

    $a->disableQueryLog();

    expect($lockedSlots)->not->toBeNull()
        ->and($deletedAvailability)->not->toBeNull()
        ->and($lockedSlots)->toBeLessThan($deletedAvailability)
        ->and(Availability::query()->count())->toBe(0)
        ->and(Slot::query()->count())->toBe(0);
});

it('regenerates a grid on the transaction’s connection when handed a pinned availability (R42)', function (): void {
    $availability = availabilityPublishedForHour();
    $slot = Slot::query()->where('availability_id', $availability->id)->orderBy('starts_at')->firstOrFail();
    $pinned = availabilityPinnedToB($availability);

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    $b->beginTransaction();
    $b->select('select id from dibs_slots where id = ? for update', [$slot->id]);

    $a->statement("set lock_timeout = '300ms'");

    try {
        (new UpdateAvailabilityGeometry)($pinned, new AvailabilityGeometry(
            $availability->starts_at,
            $availability->ends_at,
            60,
        ));
        $this->fail('Expected the regeneration to block on the default connection, not run inside session B.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    $a->statement('set lock_timeout = 0');
    $b->rollBack();

    // The geometry write is inside the same transaction, so it rolled back too.
    expect($availability->fresh()?->slot_duration_minutes)->toBe(30)
        ->and(Slot::query()->count())->toBe(2);
});

it('locks every slot before it deletes or retires one, and never retires a slot a rival is claiming (R43)', function (): void {
    $start = now()->addWeek()->startOfHour()->toImmutable();

    $availability = Availability::factory()
        ->draft()
        ->window($start, $start->addHours(2))
        ->geometry(30)
        ->create();

    (new PublishAvailability)($availability);

    $slots = Slot::query()->where('availability_id', $availability->id)->orderBy('starts_at')->get();

    // A capacity-3 slot whose only booking so far is cancelled: spent history,
    // and so exactly the slot a regeneration would otherwise retire.
    $spent = $slots[1];
    $spent->update(['capacity' => 3]);
    Booking::factory()->for($spent, 'slot')->bookedFor(user())->cancelled()->create();

    $claimant = user('Alice');

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session B is a BookSlot in flight: it holds the slot row and has not yet
    // written its booking.
    $b->beginTransaction();
    $b->select('select id from dibs_slots where id = ? for update', [$spent->id]);

    $a->statement("set lock_timeout = '300ms'");
    $a->flushQueryLog();
    $a->enableQueryLog();

    try {
        (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
            $start,
            $start->addHours(2),
            60,
        ));
        $this->fail('Expected the regeneration to block on the slot row session B holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // Taking the locks is the first thing it does, and a statement the lock
    // timeout cancels never reaches the log — so an empty list here is the
    // proof that nothing was deleted, retired or laid down while it waited.
    // Left inside a DELETE or an UPDATE, those predicates would have run
    // against a snapshot taken before session B ever committed.
    expect(availabilitySlotStatements($a))->toBe([]);

    $a->disableQueryLog();
    $a->statement('set lock_timeout = 0');

    // B turns its hold into a live claim and commits.
    $b->insert(
        'insert into dibs_bookings (slot_id, booked_for_type, booked_for_id, booked_by_type, booked_by_id, status, created_at, updated_at) '.
        'values (?, ?, ?, ?, ?, ?, now(), now())',
        [$spent->id, 'user', (string) $claimant->id, 'user', (string) $claimant->id, 'booked'],
    );
    $b->commit();

    // Re-run: the regeneration now sees the claim it was waiting behind, so the
    // slot keeps its status and its ground, and the grid skips its position.
    (new UpdateAvailabilityGeometry)($availability, new AvailabilityGeometry(
        $start,
        $start->addHours(2),
        60,
    ));

    $survivor = $spent->fresh();
    $windows = Slot::query()
        ->where('availability_id', $availability->id)
        ->orderBy('starts_at')
        ->get()
        ->map(fn (Slot $slot): string => $slot->starts_at->format('H:i').' '.$slot->status->value)
        ->all();

    expect($survivor?->status)->toBe(SlotStatus::Open)
        ->and($survivor?->capacity)->toBe(3)
        ->and(Slot::query()->retired()->count())->toBe(0)
        ->and($windows)->toBe([
            $start->addMinutes(30)->format('H:i').' open',
            $start->addHour()->format('H:i').' open',
        ]);
});
