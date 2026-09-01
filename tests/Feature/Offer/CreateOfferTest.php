<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Actions\BookSlot;
use RobinsonRyan\Dibs\Actions\CreateOffer;
use RobinsonRyan\Dibs\Data\AdhocSlotSpec;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Exceptions\SlotNotOfferable;
use RobinsonRyan\Dibs\Exceptions\SlotUnavailable;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Booking;
use RobinsonRyan\Dibs\Models\Offer;
use RobinsonRyan\Dibs\Models\OfferSlot;
use RobinsonRyan\Dibs\Models\Slot;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function spec(int $daysAhead = 3, int $minutes = 45, ?string $location = 'Bishop office', int $capacity = 1): AdhocSlotSpec
{
    $start = CarbonImmutable::now('UTC')->addDays($daysAhead)->startOfHour();

    return new AdhocSlotSpec($start, $start->addMinutes($minutes), $location, $capacity);
}

it('holds existing open slots and creates adhoc specs as held, mixed in one offer (R24)', function (): void {
    $invitee = user('Invitee');
    $bishop = user('Bishop');
    $existing = Slot::factory()->create();

    $offer = (new CreateOffer)(
        $invitee,
        [$existing, spec()],
        CarbonImmutable::now('UTC')->addDays(2),
        $bishop,
        'Pick whichever suits you.',
        ['reason' => 'temple-recommend'],
    );

    $adhoc = $offer->slots->first(fn (Slot $slot): bool => $slot->isAdhoc());

    expect($offer->status)->toBe(OfferStatus::Pending)
        ->and($offer->id)->toBeUuidV7()
        ->and($offer->offered_to_type)->toBe('user')
        ->and($offer->offered_to_id)->toBe((string) $invitee->getKey())
        ->and($offer->created_by_type)->toBe('user')
        ->and($offer->created_by_id)->toBe((string) $bishop->getKey())
        ->and($offer->expires_at?->toIso8601String())->toBe(CarbonImmutable::now('UTC')->addDays(2)->toIso8601String())
        ->and($offer->message)->toBe('Pick whichever suits you.')
        ->and($offer->meta)->toBe(['reason' => 'temple-recommend'])
        ->and($offer->slots)->toHaveCount(2)
        ->and($existing->refresh()->status)->toBe(SlotStatus::Held)
        ->and($adhoc)->not->toBeNull()
        ->and($adhoc?->status)->toBe(SlotStatus::Held)
        ->and($adhoc?->location)->toBe('Bishop office')
        ->and($adhoc?->capacity)->toBe(1)
        ->and($adhoc?->starts_at->toIso8601String())->toBe(CarbonImmutable::now('UTC')->addDays(3)->startOfHour()->toIso8601String())
        ->and($adhoc?->ends_at->toIso8601String())->toBe(CarbonImmutable::now('UTC')->addDays(3)->startOfHour()->addMinutes(45)->toIso8601String())
        ->and(OfferSlot::query()->where('offer_id', $offer->getKey())->count())->toBe(2);
});

it('loads slots, offeredTo and createdBy onto the returned offer', function (): void {
    $invitee = user('Invitee');

    $offer = (new CreateOffer)($invitee, [Slot::factory()->create()]);

    expect($offer->relationLoaded('slots'))->toBeTrue()
        ->and($offer->relationLoaded('offeredTo'))->toBeTrue()
        ->and($offer->relationLoaded('createdBy'))->toBeTrue()
        ->and($offer->offeredTo?->getKey())->toBe($invitee->getKey())
        ->and($offer->createdBy)->toBeNull();
});

it('refuses a capacity-2 slot and writes nothing (R25, D12)', function (): void {
    $invitee = user('Invitee');
    $slot = Slot::factory()->capacity(2)->create();

    expect(fn (): Offer => (new CreateOffer)($invitee, [$slot]))->toThrow(SlotNotOfferable::class)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Offer::query()->count())->toBe(0);
});

it('refuses an adhoc spec asking for capacity above one (D12)', function (): void {
    $invitee = user('Invitee');

    expect(fn (): Offer => (new CreateOffer)($invitee, [spec(capacity: 2)]))->toThrow(InvalidArgumentException::class)
        ->and(Slot::query()->whereNull('availability_id')->count())->toBe(0)
        ->and(Offer::query()->count())->toBe(0);
});

it('refuses a slot already held by another offer', function (): void {
    $first = (new CreateOffer)(user('First'), [Slot::factory()->create()]);
    $held = $first->slots->first();

    expect(fn (): Offer => (new CreateOffer)(user('Second'), [$held]))->toThrow(SlotNotOfferable::class)
        ->and(Offer::query()->count())->toBe(1)
        ->and($held?->refresh()->status)->toBe(SlotStatus::Held);
});

it('refuses a booked slot', function (): void {
    $slot = Slot::factory()->create();
    (new BookSlot)($slot, user('Member'), user('Clerk'));

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [$slot]))->toThrow(SlotNotOfferable::class)
        ->and(Offer::query()->count())->toBe(0);
});

it('refuses a slot that has already started', function (): void {
    $slot = Slot::factory()->past()->create();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [$slot]))->toThrow(SlotNotOfferable::class)
        ->and(Offer::query()->count())->toBe(0);
});

it('requires at least one slot', function (): void {
    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), []))->toThrow(InvalidArgumentException::class)
        ->and(Offer::query()->count())->toBe(0);
});

it('rolls the whole offer back when a later slot is refused', function (): void {
    $good = Slot::factory()->create();
    $bad = Slot::factory()->capacity(2)->create();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [$good, spec(), $bad]))->toThrow(SlotNotOfferable::class)
        ->and($good->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Offer::query()->count())->toBe(0)
        ->and(OfferSlot::query()->count())->toBe(0)
        ->and(Slot::query()->whereNull('availability_id')->count())->toBe(0);
});

it('generates a unique token of at least forty characters that alone fetches the pending offer (R26)', function (): void {
    $one = (new CreateOffer)(user('One'), [Slot::factory()->create()]);
    $two = (new CreateOffer)(user('Two'), [Slot::factory()->create()]);

    expect(mb_strlen($one->token))->toBe(48)
        ->and($one->token)->not->toBe($two->token)
        ->and(Offer::query()->pending()->where('token', $one->token)->first()?->getKey())->toBe($one->getKey());
});

it('honours a longer configured token length but never drops below forty (R26)', function (): void {
    config()->set('dibs.token_length', 64);
    $long = (new CreateOffer)(user('Long'), [Slot::factory()->create()]);

    config()->set('dibs.token_length', 10);
    $short = (new CreateOffer)(user('Short'), [Slot::factory()->create()]);

    expect(mb_strlen($long->token))->toBe(64)
        ->and(mb_strlen($short->token))->toBe(40);
});

it('takes a held slot out of the bookable scope and refuses a direct booking on it (R32)', function (): void {
    $availability = Availability::factory()->published()->create();
    $slot = Slot::factory()->for($availability)->create();

    expect(Slot::query()->bookable()->pluck('id')->all())->toBe([$slot->id]);

    (new CreateOffer)(user('Invitee'), [$slot]);

    expect(Slot::query()->bookable()->count())->toBe(0)
        ->and(fn (): Booking => (new BookSlot)($slot->refresh(), user('Gatecrasher'), user('Gatecrasher')))
        ->toThrow(SlotUnavailable::class);
});

it('refuses an adhoc spec whose window does not move forward, writing nothing', function (int $minutes): void {
    $start = CarbonImmutable::now('UTC')->addDays(3)->startOfHour();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [new AdhocSlotSpec($start, $start->addMinutes($minutes))]))
        ->toThrow(InvalidArgumentException::class)
        ->and(Slot::query()->count())->toBe(0)
        ->and(Offer::query()->count())->toBe(0);
})->with([0, -45]);

it('refuses an adhoc spec that starts in the past, writing nothing', function (): void {
    $start = CarbonImmutable::now('UTC')->subHour();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [new AdhocSlotSpec($start, $start->addMinutes(30))]))
        ->toThrow(InvalidArgumentException::class)
        ->and(Slot::query()->count())->toBe(0)
        ->and(Offer::query()->count())->toBe(0);
});

it('releases nothing and holds nothing when a later adhoc spec is invalid', function (): void {
    $existing = Slot::factory()->create();
    $past = CarbonImmutable::now('UTC')->subHour();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [$existing, new AdhocSlotSpec($past, $past->addMinutes(30))]))
        ->toThrow(InvalidArgumentException::class)
        ->and($existing->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Offer::query()->count())->toBe(0)
        ->and(OfferSlot::query()->count())->toBe(0);
});

it('refuses an expiry that has already passed, writing nothing', function (): void {
    $slot = Slot::factory()->create();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::now('UTC')->subMinute()))
        ->toThrow(InvalidArgumentException::class)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Open)
        ->and(Offer::query()->count())->toBe(0);
});

it('refuses an expiry of exactly now, writing nothing', function (): void {
    $slot = Slot::factory()->create();

    expect(fn (): Offer => (new CreateOffer)(user('Invitee'), [$slot], CarbonImmutable::now('UTC')))
        ->toThrow(InvalidArgumentException::class)
        ->and(Offer::query()->count())->toBe(0);
});

it('holds a slot named twice once (R24)', function (): void {
    $slot = Slot::factory()->create();

    $offer = (new CreateOffer)(user('Invitee'), [$slot, $slot]);

    expect($offer->slots)->toHaveCount(1)
        ->and(OfferSlot::query()->where('offer_id', $offer->getKey())->count())->toBe(1)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Held);
});
