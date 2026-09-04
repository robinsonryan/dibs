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
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;
use RobinsonRyan\Dibs\Database\Factories\SlotFactory;
use RobinsonRyan\Dibs\Enums\AvailabilityStatus;
use RobinsonRyan\Dibs\Enums\BookingStatus;
use RobinsonRyan\Dibs\Enums\SlotOrigin;
use RobinsonRyan\Dibs\Enums\SlotStatus;
use RobinsonRyan\Dibs\Support\Dibs;
use RobinsonRyan\Dibs\Support\HostResolution;
use RobinsonRyan\Dibs\Support\OverlapCheck;
use RobinsonRyan\Dibs\Support\SlotCapacity;
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
 * @property int|null $capacity
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
     * How many appointments this slot can take. A numbered `capacity` column is
     * that number, whatever its availability's pool says: the pool is then a
     * candidate list of who might take the appointment, not a count of how many
     * the time seats.
     *
     * A **null** column is the pool-derived kind (D18): the number of people
     * its availability's pool resolves to who have nothing else booked across
     * it. A pool that resolves to nobody is vacant and returns zero, which is
     * also when `bookable(requireFreeHost: true)` drops it; a null column with
     * no pool behind it — an adhoc slot, or an availability nobody was pooled
     * on — has nobody to be measured by and seats one.
     *
     * The rule lives in `Support\SlotCapacity`, which is also what `BookSlot`
     * gates and settles on and what `Support\ReleaseSlot` settles back against
     * — one definition, so a slot cannot be refused a claim it was just told it
     * had room for. Those callers ask with `exclusive_hosts` off, because they
     * subtract the slot's own claims by counting them; here the config stands,
     * so a host already claimed on a pool-derived slot drops out when hosts are
     * exclusive and this number is what is left.
     *
     * `$now` names the moment the pool is resolved at, defaulting to this
     * slot's start — who holds the position when the appointment happens.
     */
    public function capacityFor(?CarbonInterface $now = null): int
    {
        return SlotCapacity::of($this, null, $now);
    }

    /**
     * Open, on a published availability, in the future, and inside the
     * availability's notice/horizon window — measured against `$now` (a UTC
     * instant; defaults to the clock). Held slots never appear here (R32).
     *
     * With `$requireFreeHost`, a slot also drops out when its availability has
     * a host pool and no member of that pool is free across it (D15) — what a
     * member is offered, as opposed to what a leader may book into. An
     * availability with no pool is never excluded: there is nobody to be busy.
     * Off by default, so `bookable()` alone means what it always meant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBookable(Builder $query, ?CarbonInterface $now = null, bool $requireFreeHost = false): Builder
    {
        $now = self::instant($now);

        // The availability is read through a derived table that carries no
        // `starts_at`, so the unqualified `starts_at` in the raw fragments
        // resolves to the slot row (the outer query) — no prefixed identifiers
        // in raw SQL, and the extended model's scopes still apply.
        $availability = Dibs::query(Availability::class)
            ->select(['id', 'status', 'min_notice_minutes', 'max_horizon_days']);

        $query = $query
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

        if (! $requireFreeHost) {
            return $query;
        }

        // SQL cannot call PHP, and a pool entry may stand for somebody other
        // than itself (`HostResolver`), so the pools of the availabilities this
        // query can reach are resolved first — one query for the pool rows, one
        // for the moments to resolve them at, and the resolver once per distinct
        // (entry, context, date) however many pool rows and availabilities name
        // it (`Support\HostResolution`) — and the people that come back are
        // handed to the busy check as a values list. Resolving before the outer
        // query runs means the moment used is the *availability's* start rather
        // than each slot's, which is the same calendar day; `capacityFor()`
        // resolves per slot.
        $resolved = $this->resolvedPool($query);

        // Keep the slot when its availability has no pool at all (nobody to be
        // busy), or when at least one person the pool resolves to has nothing
        // else booked across it. Both pool reads are derived tables, so the
        // `dibs_slots` inside the busy one cannot shadow the outer slot row the
        // comparisons correlate against. A pool that resolves to nobody leaves
        // the values list empty and only the first branch can save a slot —
        // which is exactly "vacant, so unbookable".
        return $query->where(function (Builder $free) use ($resolved): void {
            $free->whereNotExists(fn (QueryBuilder $pool): QueryBuilder => $pool
                ->fromSub($this->poolOf(), 'pool')
                ->whereColumn('pool.availability_id', $this->qualifyColumn('availability_id')));

            if (! $resolved instanceof QueryBuilder) {
                return;
            }

            $free->orWhereExists(function (QueryBuilder $member) use ($resolved): void {
                $member
                    ->fromSub($resolved, 'pool')
                    ->whereColumn('pool.availability_id', $this->qualifyColumn('availability_id'))
                    ->whereNotExists(function (QueryBuilder $busy): void {
                        $busy
                            ->fromSub($this->busyHosts(), 'busy')
                            ->whereColumn('busy.host_type', 'pool.host_type')
                            ->whereColumn('busy.host_id', 'pool.host_id')
                            // The half-open overlap of OverlapCheck::overlappingSlots,
                            // restated between two slot rows (B37).
                            ->whereColumn('busy.starts_at', '<', $this->qualifyColumn('ends_at'))
                            ->whereColumn('busy.ends_at', '>', $this->qualifyColumn('starts_at'));

                        // A booking on this very slot never makes its own host
                        // busy for it (D15/B38) — unless hosts are exclusive
                        // (D18) *and* the slot is measured by its pool, when
                        // one claim takes the host out. A numbered capacity is
                        // the whole of that slot's cap, so its own claims never
                        // change who counts as free for it (B43).
                        $busy->where(function (QueryBuilder $own): void {
                            $own->whereColumn('busy.slot_id', '!=', $this->qualifyColumn('id'));

                            if (! OverlapCheck::hostsAreExclusive()) {
                                return;
                            }

                            $own->orWhereNull($this->qualifyColumn('capacity'));
                        });
                    });
            });
        });
    }

    /**
     * The pools of every availability this query can reach, put through the
     * bound `HostResolver`, as a `(availability_id, host_type, host_id)` values
     * list ready to be joined against. Null when nothing resolves — no pools at
     * all, or every pool vacant.
     *
     * The resolver is asked once per distinct pool host per availability — not
     * once per pool row per slot — and once only for a host two availabilities
     * of the same date both pool (`Support\HostResolution`).
     *
     * @param  Builder<static>  $query
     */
    private function resolvedPool(Builder $query): ?QueryBuilder
    {
        $availabilityIds = (clone $query)
            ->select($this->qualifyColumn('availability_id'))
            ->distinct();

        $pool = Dibs::query(AvailabilityHost::class)
            ->whereIn('availability_id', $availabilityIds)
            ->orderBy('id')
            ->get();

        if ($pool->isEmpty()) {
            return null;
        }

        $pool->load('host');

        // The whole availability, not just its start: the resolver is also told
        // which context is asking, because a pooled position may be a catalog
        // row several contexts share.
        $availabilities = Dibs::query(Availability::class)
            ->whereKey($pool->pluck('availability_id')->unique()->all())
            ->with('context')
            ->get()
            ->keyBy(fn (Availability $availability): string => (string) $availability->getKey());

        $resolution = new HostResolution;
        $values = null;
        $seen = [];

        foreach ($pool as $member) {
            $host = $member->host;
            $availability = $availabilities->get($member->availability_id);

            if (! $host instanceof Model || ! $availability instanceof Availability) {
                continue;
            }

            foreach ($resolution->holders($host, $availability->starts_at, $availability->context) as $holder) {
                $row = [$member->availability_id, $holder->getMorphClass(), (string) $holder->getKey()];
                $key = implode('|', $row);

                // Two entries standing for the same person are one row: the
                // busy check is an EXISTS, but a values list that repeats a
                // person makes the plan wider for nothing.
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $select = DB::query()->selectRaw(
                    'cast(? as uuid) as availability_id, cast(? as varchar) as host_type, cast(? as varchar) as host_id',
                    $row,
                );

                $values = $values instanceof QueryBuilder ? $values->unionAll($select) : $select;
            }
        }

        return $values;
    }

    /**
     * The host pool, keyed by availability — a derived table so the outer
     * query's own columns stay reachable from the correlations above.
     *
     * @return Builder<AvailabilityHost>
     */
    private function poolOf(): Builder
    {
        $host = Dibs::make(AvailabilityHost::class);

        return Dibs::query(AvailabilityHost::class)->select([
            $host->qualifyColumn('availability_id'),
            $host->qualifyColumn('host_type'),
            $host->qualifyColumn('host_id'),
        ]);
    }

    /**
     * Every live host assignment flattened onto the time it occupies.
     *
     * @return Builder<BookingHost>
     */
    private function busyHosts(): Builder
    {
        $assignment = Dibs::make(BookingHost::class);
        $booking = Dibs::make(Booking::class);
        $slot = Dibs::make(Slot::class);

        return Dibs::query(BookingHost::class)
            ->join($booking->getTable(), $booking->qualifyColumn('id'), '=', $assignment->qualifyColumn('booking_id'))
            ->join($slot->getTable(), $slot->qualifyColumn('id'), '=', $booking->qualifyColumn('slot_id'))
            ->where($booking->qualifyColumn('status'), BookingStatus::Booked->value)
            ->select([
                $assignment->qualifyColumn('host_type'),
                $assignment->qualifyColumn('host_id'),
                $booking->qualifyColumn('slot_id'),
                $slot->qualifyColumn('starts_at'),
                $slot->qualifyColumn('ends_at'),
            ]);
    }

    /**
     * Any live slot starting after `$now` (open, held or booked — never retired).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query
            ->where($this->qualifyColumn('status'), '!=', SlotStatus::Retired->value)
            ->where($this->qualifyColumn('starts_at'), '>', self::instant($now));
    }

    /**
     * Slots displaced by a grid regeneration that survive only as history.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), SlotStatus::Retired->value);
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
