<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
 * @property int|null $min_notice_minutes
 * @property int|null $max_horizon_days
 * @property AvailabilityStatus $status
 * @property array<string, mixed> $meta
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',
        'slot_padding_minutes' => 0,
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
            'min_notice_minutes' => 'integer',
            'max_horizon_days' => 'integer',
            'status' => AvailabilityStatus::class,
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): AvailabilityFactory
    {
        return AvailabilityFactory::new();
    }

    /**
     * The owning scope (a tenant, an organization); null for single-tenant consumers.
     *
     * @return MorphTo<Model, $this>
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
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

    /**
     * Records owned by the given context (tenant / organisation).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForContext(Builder $query, Model $context): Builder
    {
        return $query
            ->where($this->qualifyColumn('context_type'), $context->getMorphClass())
            ->where($this->qualifyColumn('context_id'), (string) $context->getKey());
    }

    public function isPublished(): bool
    {
        return $this->status === AvailabilityStatus::Published;
    }
}
