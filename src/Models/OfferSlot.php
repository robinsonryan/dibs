<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\OfferSlotFactory;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\TablePrefixer;

/**
 * A slot held by an offer.
 *
 * @extensible Substitute a subclass via config('dibs.models').
 *
 * @property string $id
 * @property string $offer_id
 * @property string $slot_id
 */
class OfferSlot extends Model
{
    /** @use HasFactory<OfferSlotFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $guarded = [];

    public function getTable(): string
    {
        return TablePrefixer::prefix('offer_slots');
    }

    protected static function newFactory(): OfferSlotFactory
    {
        return OfferSlotFactory::new();
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Offer::class), 'offer_id');
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Dibs::model(Slot::class), 'slot_id');
    }
}
