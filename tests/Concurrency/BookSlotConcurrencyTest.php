<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;

afterEach(function (): void {
    DB::setDefaultConnection('testing');

    foreach (['testing', 'testing_b'] as $name) {
        $connection = DB::connection($name);

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $connection->statement('set lock_timeout = 0');
    }

    // The Feature suite shares this database in the same process; these tests
    // really commit, so nothing may survive them.
    DB::connection('testing')->statement(
        'TRUNCATE dibs_booking_hosts, dibs_bookings, dibs_offer_slots, dibs_offers, '.
        'dibs_availability_hosts, dibs_slots, dibs_availabilities, '.
        'fixture_users, fixture_rooms, fixture_organizations CASCADE',
    );
});

it('holds the slot row against a second session while a booking is in flight (R13)', function (): void {
    // Two units, so the winning session changes no slot column — the only thing
    // standing between the sessions is the row lock BookSlot takes to read.
    $slot = Slot::factory()->capacity(2)->create();
    $alice = user('Alice');
    $bob = user('Bob');
    $carol = user('Carol');

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A takes one unit and keeps its transaction open.
    $a->beginTransaction();
    (new BookSlot)($slot->fresh(), $alice, $alice);

    // Session B must wait for A rather than read around it.
    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");

    try {
        (new BookSlot)($slot->fresh(), $bob, $bob);
        $this->fail('Expected session B to block on the row session A is booking.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    // Once A commits, B sees the truth and takes the remaining unit.
    DB::setDefaultConnection('testing');
    $a->commit();

    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');
    (new BookSlot)($slot->fresh(), $bob, $bob);

    // And the slot is full: a third claim is refused, never over-booked.
    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $carol, $carol))
        ->toThrow(SlotUnavailable::class);

    DB::setDefaultConnection('testing');

    expect(Booking::active()->count())->toBe(2)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('lets exactly one of two contending sessions take the last unit of capacity (R13)', function (): void {
    $slot = Slot::factory()->create();
    $alice = user('Alice');
    $bob = user('Bob');

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A claims the only unit, transaction still open.
    $a->beginTransaction();
    $booking = (new BookSlot)($slot->fresh(), $alice, $alice);

    // Session B contends for the same unit and is made to wait.
    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");

    try {
        (new BookSlot)($slot->fresh(), $bob, $bob);
        $this->fail('Expected session B to block on the row session A is booking.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    DB::setDefaultConnection('testing');
    $a->commit();

    // The lock is free now, so B reads the row under its own lock — and is refused.
    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $bob, $bob))
        ->toThrow(SlotUnavailable::class);

    DB::setDefaultConnection('testing');

    expect(Booking::active()->count())->toBe(1)
        ->and(Booking::active()->first()->id)->toBe($booking->id)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('lets two contending sessions into a pooled slot while two people are free (R67)', function (): void {
    // The capacity column says one; the pool says two are free, and the pool
    // is what a pooled slot is measured by.
    $availability = Availability::factory()->published()->create();
    AvailabilityHost::factory()->for($availability)->host(user('Alice'), 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host(user('Bob'), 'interviewer')->create();
    $slot = Slot::factory()->for($availability)->create();

    $ann = user('Ann');
    $bea = user('Bea');
    $cal = user('Cal');

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    // Session A takes the first of the two, transaction still open.
    $a->beginTransaction();
    (new BookSlot)($slot->fresh(), $ann, $ann);

    // Session B must wait for A rather than read around it.
    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");

    try {
        (new BookSlot)($slot->fresh(), $bea, $bea);
        $this->fail('Expected session B to block on the row session A is booking.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    DB::setDefaultConnection('testing');
    $a->commit();

    // Both people were free, so B takes the second one.
    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');
    (new BookSlot)($slot->fresh(), $bea, $bea);

    // And there is no third person to take a third claim.
    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $cal, $cal))
        ->toThrow(SlotUnavailable::class);

    DB::setDefaultConnection('testing');

    expect(Booking::active()->count())->toBe(2)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('lets exactly one of two contending sessions into a pooled slot with one person free (R67)', function (): void {
    $availability = Availability::factory()->published()->create();
    AvailabilityHost::factory()->for($availability)->host(user('Alice'), 'interviewer')->create();
    $slot = Slot::factory()->for($availability)->create();

    $ann = user('Ann');
    $bea = user('Bea');

    $a = DB::connection('testing');
    $b = DB::connection('testing_b');

    $a->beginTransaction();
    $booking = (new BookSlot)($slot->fresh(), $ann, $ann);

    DB::setDefaultConnection('testing_b');
    $b->statement("set lock_timeout = '300ms'");

    try {
        (new BookSlot)($slot->fresh(), $bea, $bea);
        $this->fail('Expected session B to block on the row session A is booking.');
    } catch (QueryException $blocked) {
        expect((string) $blocked->getCode())->toBe('55P03');
    }

    DB::setDefaultConnection('testing');
    $a->commit();

    // One person, one appointment: B reads the truth under its own lock and is
    // refused.
    DB::setDefaultConnection('testing_b');
    $b->statement('set lock_timeout = 0');

    expect(fn (): Booking => (new BookSlot)($slot->fresh(), $bea, $bea))
        ->toThrow(SlotUnavailable::class);

    DB::setDefaultConnection('testing');

    expect(Booking::active()->count())->toBe(1)
        ->and(Booking::active()->first()->id)->toBe($booking->id)
        ->and($slot->fresh()->status)->toBe(SlotStatus::Booked);
});
