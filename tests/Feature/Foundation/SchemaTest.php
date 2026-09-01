<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\AvailabilityHost;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\BookingHost;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Slot;

it('creates the seven prefixed tables', function (): void {
    foreach (['availabilities', 'slots', 'availability_hosts', 'bookings', 'booking_hosts', 'offers', 'offer_slots'] as $table) {
        expect(Schema::hasTable('dibs_'.$table))->toBeTrue($table);
    }
});

it('stores instants as timestamptz and meta as jsonb with an empty-object default', function (): void {
    $columns = collect(Schema::getColumns('dibs_availabilities'))->keyBy('name');

    expect($columns['starts_at']['type'])->toContain('with time zone')
        ->and($columns['meta']['type'])->toBe('jsonb')
        ->and($columns['meta']['default'])->toContain('{}');

    $id = DB::table('dibs_availabilities')->insertGetId([
        'starts_at' => now(), 'ends_at' => now()->addHour(), 'slot_duration_minutes' => 30,
    ], 'id');

    expect(DB::table('dibs_availabilities')->where('id', $id)->value('meta'))->toBe('{}');
});

it('generates uuid v7 primary keys in the database for every model', function (string $model): void {
    $instance = $model::factory()->create();

    expect($instance->getKey())->toBeUuidV7()
        ->and($instance->fresh())->not->toBeNull();
})->with([
    Availability::class,
    Slot::class,
    AvailabilityHost::class,
    Booking::class,
    BookingHost::class,
    Offer::class,
    OfferSlot::class,
]);

it('blocks a second live booking by the same person on one slot', function (): void {
    $slot = Slot::factory()->capacity(3)->create();
    $person = user();

    Booking::factory()->for($slot)->bookedFor($person)->create();

    expect(fn () => Booking::factory()->for($slot)->bookedFor($person)->create())
        ->toThrow(QueryException::class, 'live_claim_unique');
});

it('lets the same person rebook a slot once the earlier claim is cancelled', function (): void {
    $slot = Slot::factory()->capacity(3)->create();
    $person = user();

    Booking::factory()->for($slot)->bookedFor($person)->cancelled()->create();
    Booking::factory()->for($slot)->bookedFor($person)->create();

    expect($slot->bookings()->count())->toBe(2);
});

it('refuses to delete a slot that has any booking row, even a cancelled one', function (): void {
    $slot = Slot::factory()->create();
    Booking::factory()->for($slot)->cancelled()->create();

    expect(fn () => $slot->delete())->toThrow(QueryException::class);
});

it('cascades slots and pool rows when an availability is deleted', function (): void {
    $availability = Availability::factory()->create();
    Slot::factory()->for($availability)->count(2)->create();
    AvailabilityHost::factory()->for($availability)->host(user())->create();

    $availability->delete();

    expect(Slot::count())->toBe(0)->and(AvailabilityHost::count())->toBe(0);
});

it('nulls an offer\'s accepted booking when that booking is deleted, and cascades offer slots', function (): void {
    $booking = Booking::factory()->create();
    $offer = Offer::factory()->accepted()->create(['accepted_booking_id' => $booking->id]);
    OfferSlot::factory()->for($offer)->create();

    $booking->delete();
    expect($offer->fresh()?->accepted_booking_id)->toBeNull();

    $offer->delete();
    expect(OfferSlot::count())->toBe(0);
});

it('enforces one pool row per host and role, and one assignment per host and role', function (): void {
    $availability = Availability::factory()->create();
    $host = user();
    AvailabilityHost::factory()->for($availability)->host($host, 'interviewer')->create();
    AvailabilityHost::factory()->for($availability)->host($host, 'driver')->create();

    // Savepoint, so the failed insert does not abort the test's own transaction.
    expect(fn () => DB::transaction(fn () => AvailabilityHost::factory()->for($availability)->host($host, 'driver')->create()))
        ->toThrow(QueryException::class);

    $booking = Booking::factory()->create();
    BookingHost::factory()->for($booking)->host($host, 'interviewer')->create();

    expect(fn () => BookingHost::factory()->for($booking)->host($host, 'interviewer')->create())
        ->toThrow(QueryException::class);
});

it('stores morph aliases from the consumer morph map, not FQCNs', function (): void {
    $host = user();
    $row = AvailabilityHost::factory()->host($host)->create();

    expect($row->host_type)->toBe('user')
        ->and($row->host)->toBeInstanceOf($host::class)
        ->and($row->host?->is($host))->toBeTrue();
});
