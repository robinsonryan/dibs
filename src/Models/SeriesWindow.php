<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\SeriesWindowFactory;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * One stretch of hours on one weekday, as minutes from local midnight in the
 * series' timezone. Several rows on one weekday are several blocks that day.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $series_id
 * @property int $weekday 0 = Sunday … 6 = Saturday
 * @property int $starts_at_minutes
 * @property int $ends_at_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SeriesWindow extends Model
{
    /** @use HasFactory<SeriesWindowFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    public function getTable(): string
    {
        return TablePrefixer::prefix('series_windows');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'starts_at_minutes' => 'integer',
            'ends_at_minutes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): SeriesWindowFactory
    {
        return SeriesWindowFactory::new();
    }

    /**
     * @return BelongsTo<Series, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Series::class), 'series_id');
    }
}
