<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Dibs\Models\Availability;
use RobinsonRyan\Dibs\Models\Series;
use RobinsonRyan\Dibs\Models\SeriesHost;
use RobinsonRyan\Dibs\Models\SeriesWindow;

it('creates the three prefixed series tables', function (): void {
    foreach (['series', 'series_windows', 'series_hosts'] as $table) {
        expect(Schema::hasTable('dibs_'.$table))->toBeTrue($table);
    }
});

it('generates uuid v7 keys and jsonb defaults on a series', function (): void {
    $series = Series::factory()->create();

    expect($series->id)->toBeUuidV7()
        ->and($series->ordinals)->toBe([])
        ->and($series->meta)->toBe([])
        ->and($series->rule_version)->toBe(1);
});

it('refuses two series with the same title in one context, whatever the case', function (): void {
    $ward = organization('First Ward');

    Series::factory()->forContext($ward)->create(['title' => 'Tuesday and Thursday evenings']);

    expect(fn () => Series::factory()->forContext($ward)->create(['title' => 'TUESDAY AND THURSDAY EVENINGS']))
        ->toThrow(QueryException::class);
});

it('allows the same title in a different context', function (): void {
    Series::factory()->forContext(organization('First Ward'))->create(['title' => 'Evenings']);
    Series::factory()->forContext(organization('Second Ward'))->create(['title' => 'Evenings']);

    expect(Series::query()->count())->toBe(2);
});

it('refuses a window that ends before it starts', function (): void {
    $series = Series::factory()->create();

    expect(fn () => SeriesWindow::factory()->for($series)->create([
        'weekday' => 2,
        'starts_at_minutes' => 1080,
        'ends_at_minutes' => 1080,
    ]))->toThrow(QueryException::class);
});

it('cascades windows and hosts when the series goes', function (): void {
    $series = Series::factory()->create();
    SeriesWindow::factory()->for($series)->create();
    SeriesHost::factory()->for($series)->host(user('Bishop'))->create();

    $series->delete();

    expect(DB::table('dibs_series_windows')->count())->toBe(0)
        ->and(DB::table('dibs_series_hosts')->count())->toBe(0);
});

it('refuses the same host twice in one series for one role', function (): void {
    $series = Series::factory()->create();
    $bishop = user('Bishop');

    SeriesHost::factory()->for($series)->host($bishop)->create();

    expect(fn () => SeriesHost::factory()->for($series)->host($bishop)->create())
        ->toThrow(QueryException::class);
});

it('leaves occurrences behind when the series is deleted', function (): void {
    $series = Series::factory()->create();
    $occurrence = Availability::factory()->create([
        'series_id' => $series->id,
        'occurs_on' => '2026-09-08',
        'window_index' => 0,
        'rule_version' => 1,
    ]);

    $series->delete();

    expect($occurrence->fresh()?->series_id)->toBeNull();
});

it('refuses two occurrences of one series on the same date and block', function (): void {
    $series = Series::factory()->create();

    Availability::factory()->create([
        'series_id' => $series->id, 'occurs_on' => '2026-09-08', 'window_index' => 0, 'rule_version' => 1,
    ]);

    expect(fn () => Availability::factory()->create([
        'series_id' => $series->id, 'occurs_on' => '2026-09-08', 'window_index' => 0, 'rule_version' => 1,
    ]))->toThrow(QueryException::class);
});

it('allows two blocks on one date', function (): void {
    $series = Series::factory()->create();

    Availability::factory()->create(['series_id' => $series->id, 'occurs_on' => '2026-09-08', 'window_index' => 0]);
    Availability::factory()->create(['series_id' => $series->id, 'occurs_on' => '2026-09-08', 'window_index' => 1]);

    expect($series->occurrences()->count())->toBe(2);
});

it('does not constrain availabilities that belong to no series', function (): void {
    Availability::factory()->count(2)->create();

    expect(Availability::query()->whereNull('series_id')->count())->toBe(2);
});

it('reports whether an occurrence is detached', function (): void {
    $series = Series::factory()->create();

    $following = Availability::factory()->create(['series_id' => $series->id, 'occurs_on' => '2026-09-08', 'window_index' => 0]);
    $detached = Availability::factory()->create(['series_id' => $series->id, 'occurs_on' => '2026-09-15', 'window_index' => 0, 'detached_at' => now()]);

    expect($following->isDetached())->toBeFalse()
        ->and($detached->isDetached())->toBeTrue()
        ->and(Availability::query()->detached()->pluck('id')->all())->toBe([$detached->id]);
});
