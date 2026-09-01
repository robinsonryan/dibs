<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\SlotFactory;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotOrigin;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * One bookable time. Bare start/end rows: buffers live on the availability (D1).
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string|null $availability_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $location
 * @property int $capacity
 * @property SlotStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Slot extends Model
{
    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'open',
        'capacity' => 1,
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('slots');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'status' => SlotStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): SlotFactory
    {
        return SlotFactory::new();
    }

    /**
     * @return BelongsTo<Availability, $this>
     */
    public function availability(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Availability::class), 'availability_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Dibs::model(Booking::class), 'slot_id');
    }

    /**
     * Live claims only (status `booked`).
     *
     * @return HasMany<Booking, $this>
     */
    public function activeBookings(): HasMany
    {
        return $this->bookings()->where(
            Dibs::make(Booking::class)->qualifyColumn('status'),
            BookingStatus::Booked->value,
        );
    }

    /**
     * @return HasMany<OfferSlot, $this>
     */
    public function offerSlots(): HasMany
    {
        return $this->hasMany(Dibs::model(OfferSlot::class), 'slot_id');
    }

    /**
     * @return BelongsToMany<Offer, $this>
     */
    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(
            Dibs::model(Offer::class),
            TablePrefixer::prefix('offer_slots'),
            'slot_id',
            'offer_id',
        )->withTimestamps();
    }

    /**
     * Derived, never stored (§2): availability-born or adhoc.
     */
    public function origin(): SlotOrigin
    {
        return $this->availability_id === null ? SlotOrigin::Adhoc : SlotOrigin::Availability;
    }

    public function isAdhoc(): bool
    {
        return $this->availability_id === null;
    }

    /**
     * Open, on a published availability, in the future, and inside the
     * availability's notice/horizon window — measured against `$now` (a UTC
     * instant; defaults to the clock). Held slots never appear here (R32).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBookable(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = self::instant($now);

        // The availability is read through a derived table that carries no
        // `starts_at`, so the unqualified `starts_at` in the raw fragments
        // resolves to the slot row (the outer query) — no prefixed identifiers
        // in raw SQL, and the extended model's scopes still apply.
        $availability = Dibs::query(Availability::class)
            ->select(['id', 'status', 'min_notice_minutes', 'max_horizon_days']);

        return $query
            ->where($this->qualifyColumn('status'), SlotStatus::Open->value)
            ->where($this->qualifyColumn('starts_at'), '>', $now)
            ->whereExists(function (QueryBuilder $exists) use ($now, $availability): void {
                $exists
                    ->fromSub($availability, 'a')
                    ->whereColumn('a.id', $this->qualifyColumn('availability_id'))
                    ->where('a.status', AvailabilityStatus::Published->value)
                    ->where(function (QueryBuilder $notice) use ($now): void {
                        $notice
                            ->whereNull('a.min_notice_minutes')
                            ->orWhereRaw('starts_at >= ?::timestamptz + make_interval(mins => a.min_notice_minutes)', [$now]);
                    })
                    ->where(function (QueryBuilder $horizon) use ($now): void {
                        $horizon
                            ->whereNull('a.max_horizon_days')
                            ->orWhereRaw('starts_at <= ?::timestamptz + make_interval(days => a.max_horizon_days)', [$now]);
                    });
            });
    }

    /**
     * Any slot starting after `$now`, regardless of status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->where($this->qualifyColumn('starts_at'), '>', self::instant($now));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), SlotStatus::Open->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), SlotStatus::Held->value);
    }

    /**
     * Normalise the reference instant to UTC (D10). The package never converts
     * to a wall clock; consumers do that at their boundary.
     */
    public static function instant(?CarbonInterface $now): CarbonImmutable
    {
        return $now instanceof CarbonInterface
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');
    }
}
