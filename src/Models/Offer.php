<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RobinsonRyan\Dibs\Concerns\HasContext;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\OfferFactory;
use RobinsonRyan\Dibs\Enums\OfferStatus;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * A tokenized multi-slot invitation: the invitee picks one, the rest are released.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $token
 * @property string|null $context_type
 * @property string|null $context_id
 * @property string $offered_to_type
 * @property string $offered_to_id
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property OfferStatus $status
 * @property string|null $accepted_booking_id
 * @property string|null $message
 * @property array<string, mixed> $meta
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class Offer extends Model
{
    use HasContext;

    /** @use HasFactory<OfferFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
        'meta' => '{}',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('offers');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'status' => OfferStatus::class,
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): OfferFactory
    {
        return OfferFactory::new();
    }

    /**
     * Only this party may accept.
     *
     * @return MorphTo<Model, $this>
     */
    public function offeredTo(): MorphTo
    {
        return $this->morphTo('offeredTo', 'offered_to_type', 'offered_to_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('createdBy', 'created_by_type', 'created_by_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function acceptedBooking(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Booking::class), 'accepted_booking_id');
    }

    /**
     * @return HasMany<OfferSlot, $this>
     */
    public function offerSlots(): HasMany
    {
        return $this->hasMany(Dibs::model(OfferSlot::class), 'offer_id');
    }

    /**
     * @return BelongsToMany<Slot, $this>
     */
    public function slots(): BelongsToMany
    {
        return $this->belongsToMany(
            Dibs::model(Slot::class),
            TablePrefixer::prefix('offer_slots'),
            'offer_id',
            'slot_id',
        )->withTimestamps();
    }

    /**
     * Status pending AND unexpired as of `$now`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = Slot::instant($now);

        return $query
            ->where($this->qualifyColumn('status'), OfferStatus::Pending->value)
            ->where(function (Builder $unexpired) use ($now): void {
                $unexpired
                    ->whereNull($this->qualifyColumn('expires_at'))
                    ->orWhere($this->qualifyColumn('expires_at'), '>', $now);
            });
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(Slot::instant($now));
    }
}
