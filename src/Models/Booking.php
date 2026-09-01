<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\BookingFactory;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * The claim on a slot.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $slot_id
 * @property string $booked_for_type
 * @property string $booked_for_id
 * @property string $booked_by_type
 * @property string $booked_by_id
 * @property string|null $type
 * @property BookingStatus $status
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_type
 * @property string|null $cancelled_by_id
 * @property array<string, mixed> $meta
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'booked',
        'meta' => '{}',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('bookings');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'cancelled_at' => 'immutable_datetime',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Slot::class), 'slot_id');
    }

    /**
     * The subject of the booking.
     *
     * @return MorphTo<Model, $this>
     */
    public function bookedFor(): MorphTo
    {
        return $this->morphTo('bookedFor', 'booked_for_type', 'booked_for_id');
    }

    /**
     * The submitter; equals bookedFor unless booked on someone's behalf.
     *
     * @return MorphTo<Model, $this>
     */
    public function bookedBy(): MorphTo
    {
        return $this->morphTo('bookedBy', 'booked_by_type', 'booked_by_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function cancelledBy(): MorphTo
    {
        return $this->morphTo('cancelledBy', 'cancelled_by_type', 'cancelled_by_id');
    }

    /**
     * Host assignment (who fulfils this booking), by role.
     *
     * @return HasMany<BookingHost, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(Dibs::model(BookingHost::class), 'booking_id');
    }

    /**
     * Live claims: status `booked`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), BookingStatus::Booked->value);
    }

    /**
     * Active bookings whose slot starts after `$now`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = Slot::instant($now);

        return $query
            ->active()
            ->whereHas('slot', fn (Builder $slot): Builder => $slot->where(
                Dibs::make(Slot::class)->qualifyColumn('starts_at'),
                '>',
                $now,
            ));
    }

    public function isActive(): bool
    {
        return $this->status === BookingStatus::Booked;
    }
}
