<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\SeriesHostFactory;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * The series' pool: who may fulfil bookings on the occurrences it makes, by
 * role. Every occurrence is given its own copy at materialisation, so changing
 * the series' pool is a rule change and a booked occurrence keeps the pool it
 * was booked under.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $series_id
 * @property string $host_type
 * @property string $host_id
 * @property string $role
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SeriesHost extends Model
{
    /** @use HasFactory<SeriesHostFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'role' => 'host',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('series_hosts');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): SeriesHostFactory
    {
        return SeriesHostFactory::new();
    }

    /**
     * @return BelongsTo<Series, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Series::class), 'series_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function host(): MorphTo
    {
        return $this->morphTo('host');
    }
}
