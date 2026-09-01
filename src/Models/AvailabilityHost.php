<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\AvailabilityHostFactory;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * The host pool: a host (any consumer model) attached to an availability with a role.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $availability_id
 * @property string $host_type
 * @property string $host_id
 * @property string $role
 */
class AvailabilityHost extends Model
{
    /** @use HasFactory<AvailabilityHostFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    protected $attributes = [
        'role' => 'host',
    ];

    public function getTable(): string
    {
        return TablePrefixer::prefix('availability_hosts');
    }

    protected static function newFactory(): AvailabilityHostFactory
    {
        return AvailabilityHostFactory::new();
    }

    /**
     * @return BelongsTo<Availability, $this>
     */
    public function availability(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Availability::class), 'availability_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function host(): MorphTo
    {
        return $this->morphTo('host');
    }
}
