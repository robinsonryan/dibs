<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RobinsonRyan\Dibs\Concerns\HasContext;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\AvailabilityFactory;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * A published window of bookable time. Slots are generated from its geometry.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string|null $context_type
 * @property string|null $context_id
 * @property string|null $type
 * @property string|null $name
 * @property string|null $location
 * @property \Carbon\CarbonImmutable $starts_at
 * @property \Carbon\CarbonImmutable $ends_at
 * @property int $slot_duration_minutes
 * @property int $slot_padding_minutes
 * @property bool $capacity_from_pool
 * @property int|null $min_notice_minutes
 * @property int|null $max_horizon_days
 * @property AvailabilityStatus $status
 * @property array<string, mixed> $meta
 * @property string|null $series_id
 * @property \Carbon\CarbonImmutable|null $occurs_on
 * @property int|null $window_index
 * @property int|null $rule_version
 * @property \Carbon\CarbonImmutable|null $detached_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class Availability extends Model
{
    use HasContext;

    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',
        'slot_padding_minutes' => 0,
        'capacity_from_pool' => false,
        'meta' => '{}',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('availabilities');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'slot_duration_minutes' => 'integer',
            'slot_padding_minutes' => 'integer',
            'capacity_from_pool' => 'boolean',
            'min_notice_minutes' => 'integer',
            'max_horizon_days' => 'integer',
            'status' => AvailabilityStatus::class,
            'meta' => 'array',
            'occurs_on' => 'immutable_date',
            'window_index' => 'integer',
            'rule_version' => 'integer',
            'detached_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): AvailabilityFactory
    {
        return AvailabilityFactory::new();
    }

    /**
     * The rule this occurrence was materialised from; null for an availability
     * opened by hand, and null again once its series is deleted (its bookings
     * are history and outlive the rule).
     *
     * @return BelongsTo<Series, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Series::class), 'series_id');
    }

    /**
     * The capacity to write on the slots this availability materialises: null
     * when its times are measured by the host pool (D18), and the column's own
     * default — one appointment — when they are not. Every path that lays a
     * grid down reads it here, so publishing, a geometry edit and a series
     * regeneration cannot disagree about what kind of time this day opens.
     */
    public function slotCapacity(): ?int
    {
        return $this->capacity_from_pool ? null : 1;
    }

    /**
     * @return HasMany<Slot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Dibs::model(Slot::class), 'availability_id');
    }

    /**
     * The host pool: who may fulfil bookings on this availability, by role.
     *
     * @return HasMany<AvailabilityHost, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(Dibs::model(AvailabilityHost::class), 'availability_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), AvailabilityStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === AvailabilityStatus::Published;
    }

    /**
     * Occurrences edited by hand, which regeneration, pause and resume leave
     * to their owner (D7 of the consumer's rule: editing only ever changes what
     * still follows the series).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDetached(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('detached_at'));
    }

    public function isDetached(): bool
    {
        return $this->detached_at !== null;
    }
}
