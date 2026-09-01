<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\AssignBookingHost;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Exceptions\InvalidTransition;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Slot;

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

    DB::connection('testing')->statement(
        'TRUNCATE dibs_booking_hosts, dibs_bookings, dibs_offer_slots, dibs_offers, '.
        'dibs_availability_hosts, dibs_slots, dibs_availabilities, '.
        'fixture_users, fixture_rooms, fixture_organizations CASCADE',
    );
});

/**
 * Every statement this connection got an answer to. A statement cancelled by the
 * lock timeout never lands here, so an empty list means the action blocked on its
 * very first query — it wrote nothing and read nothing from around the lock.
 *
 * @return list<string>
 */
function bookingHostStatements(Connection $connection): array
{
    $queries = [];

    foreach ($connection->getQueryLog() as $entry) {
        $queries[] = is_string($entry['query'] ?? null) ? $entry['query'] : '';
    }

    return $queries;
}

it('waits for a rival session instead of assigning a host from a stale copy (R43)', function (): void {
    $slot = Slot::factory()->create();
    $member = user('Member');
    $booking = (new BookSlot)($slot, $member, $member);
    $alice = user('Alice');

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A is deciding this booking's fate — uncommitted.
    $a->beginTransaction();
    $a->select('select id from dibs_bookings where id = ? for update', [$booking->id]);

    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");
    $b->flushQueryLog();
    $b->enableQueryLog();

    try {
        (new AssignBookingHost)($booking, $alice, 'interviewer');
        $this->fail('Expected session B to block on the booking row session A holds.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // Nothing assigned, nothing deleted: it blocked on its first statement.
    expect(bookingHostStatements($b))->toBe([]);

    $b->disableQueryLog();

    DB::setDefaultConnection('testing');
    $a->update('update dibs_bookings set status = ? where id = ?', ['cancelled', $booking->id]);
    $a->commit();

    // A cancelled booking is frozen — and B's in-memory copy still says booked.
    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn (): Booking => (new AssignBookingHost)($booking, $alice, 'interviewer'))
        ->toThrow(InvalidTransition::class);

    DB::setDefaultConnection('testing');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and(BookingHost::query()->count())->toBe(0);
});
