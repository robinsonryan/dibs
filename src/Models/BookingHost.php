<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\BookingHostFactory;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * Host assignment: a host (any consumer model) assigned to a booking with a role.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $booking_id
 * @property string $host_type
 * @property string $host_id
 * @property string $role
 */
class BookingHost extends Model
{
    /** @use HasFactory<BookingHostFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'role' => 'host',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('booking_hosts');
    }

    protected static function newFactory(): BookingHostFactory
    {
        return BookingHostFactory::new();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Booking::class), 'booking_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function host(): MorphTo
    {
        return $this->morphTo('host');
    }
}
