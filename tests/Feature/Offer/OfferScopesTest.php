<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Dibs\Models\Offer;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('pendingFor: only this party’s live invitations (R48)', function (): void {
    $member = user('Member');
    $someoneElse = user('Someone Else');

    $open = Offer::factory()->offeredTo($member)->pending()->expiresAt(null)->create();
    $future = Offer::factory()->offeredTo($member)->pending()->expiresAt(CarbonImmutable::now()->addHour())->create();

    Offer::factory()->offeredTo($member)->overdue()->create();
    Offer::factory()->offeredTo($member)->accepted()->create();
    Offer::factory()->offeredTo($member)->withdrawn()->create();
    Offer::factory()->offeredTo($someoneElse)->pending()->create();

    expect(Offer::pendingFor($member)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$open->id, $future->id])->sort()->values()->all())
        ->and(Offer::pendingFor($someoneElse)->count())->toBe(1);
});

it('pendingFor: matches the party’s type as well as its id (R48)', function (): void {
    $member = user('Member');
    $room = room('Room 1');

    $wanted = Offer::factory()->offeredTo($member)->pending()->create();

    // Same id, different morph type: a type-blind scope would return both.
    Offer::factory()->pending()->create([
        'offered_to_type' => $room->getMorphClass(),
        'offered_to_id' => (string) $member->getKey(),
    ]);

    expect(Offer::pendingFor($member)->pluck('id')->all())->toBe([$wanted->id]);
});

it('createdBy: every offer this party raised, whatever its status (R48)', function (): void {
    $leader = user('Leader');
    $other = user('Other Leader');

    $pending = Offer::factory()->createdBy($leader)->pending()->create();
    $withdrawn = Offer::factory()->createdBy($leader)->withdrawn()->create();
    Offer::factory()->createdBy($other)->pending()->create();
    Offer::factory()->pending()->create();

    expect(Offer::query()->createdBy($leader)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$pending->id, $withdrawn->id])->sort()->values()->all());
});

it('createdBy: composes with pendingFor (R48)', function (): void {
    $leader = user('Leader');
    $member = user('Member');

    $wanted = Offer::factory()->createdBy($leader)->offeredTo($member)->pending()->create();
    Offer::factory()->createdBy($leader)->offeredTo($member)->overdue()->create();
    Offer::factory()->createdBy($leader)->offeredTo(user('Another'))->pending()->create();

    expect(Offer::pendingFor($member)->createdBy($leader)->pluck('id')->all())->toBe([$wanted->id]);
});
