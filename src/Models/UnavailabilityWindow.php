<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\UnavailabilityWindowFactory;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * One stretch of hours on one weekday a standing away takes out, as minutes
 * from local midnight in the away's own timezone.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $unavailability_id
 * @property int $weekday 0 = Sunday … 6 = Saturday
 * @property int $starts_at_minutes
 * @property int $ends_at_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class UnavailabilityWindow extends Model
{
    /** @use HasFactory<UnavailabilityWindowFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    public function getTable(): string
    {
        return TablePrefixer::prefix('unavailability_windows');
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

    protected static function newFactory(): UnavailabilityWindowFactory
    {
        return UnavailabilityWindowFactory::new();
    }

    /**
     * @return BelongsTo<Unavailability, $this>
     */
    public function unavailability(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Unavailability::class), 'unavailability_id');
    }
}
