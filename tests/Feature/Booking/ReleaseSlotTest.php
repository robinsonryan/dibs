<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Support\ReleaseSlot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns an availability-born held slot to open (D3)', function (): void {
    $slot = Slot::factory()->held()->create();

    (new ReleaseSlot)($slot);

    expect($slot->fresh()->status)->toBe(SlotStatus::Open);
});

it('reopens an availability-born booked slot once its active bookings fall below capacity', function (): void {
    $slot = Slot::factory()->booked()->capacity(2)->create();
    Booking::factory()->for($slot, 'slot')->cancelled()->create();

    (new ReleaseSlot)($slot);

    expect($slot->fresh()->status)->toBe(SlotStatus::Open);
});

it('leaves a still-full slot booked', function (): void {
    $slot = Slot::factory()->booked()->create();
    Booking::factory()->for($slot, 'slot')->booked()->create();

    (new ReleaseSlot)($slot);

    expect($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('deletes an adhoc slot that no booking ever touched (D3)', function (): void {
    $slot = Slot::factory()->adhoc()->held()->create();

    (new ReleaseSlot)($slot);

    expect(Slot::query()->whereKey($slot->id)->exists())->toBeFalse();
});

it('keeps an adhoc slot that carries a cancelled booking, as open (D3)', function (): void {
    $slot = Slot::factory()->adhoc()->held()->create();
    Booking::factory()->for($slot, 'slot')->cancelled()->create();

    (new ReleaseSlot)($slot);

    expect($slot->fresh()->status)->toBe(SlotStatus::Open);
});

it('decides from the stored row, not a stale in-memory copy', function (): void {
    $slot = Slot::factory()->held()->create();
    Booking::factory()->for($slot, 'slot')->booked()->create();

    // The caller's copy still says capacity 5; the row says 1.
    $slot->capacity = 5;

    (new ReleaseSlot)($slot);

    expect($slot->fresh()->status)->toBe(SlotStatus::Booked);
});

it('takes a row lock before deciding', function (): void {
    $slot = Slot::factory()->held()->create();
    $locking = [];

    DB::listen(function (QueryExecuted $query) use (&$locking): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locking[] = $query->sql;
        }
    });

    (new ReleaseSlot)($slot);

    expect($locking)->toHaveCount(1)
        ->and($locking[0])->toContain('dibs_slots');
});
