<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Enums\UnavailabilityKind;
use RobinsonRyan\Dibs\Models\Slot;
use RobinsonRyan\Dibs\Models\Unavailability;

/**
 * The aways covering a stretch of time, **in memory**.
 *
 * A standing away is a wall-clock rule, and SQL cannot call PHP: which instants
 * it takes out can only be worked out in the away's own timezone, one local
 * date at a time. So the read paths fetch the rows that could matter once and
 * ask each of them in PHP, rather than asking the database once per slot — and
 * a consumer's own read-once structure (ccstake's conflict index) is handed the
 * same rows for the same reason.
 *
 * `coveringHost` and `coveringContext` are the same query under two names,
 * because a scope is a host in one reading and a whole context in the other,
 * and the caller always knows which it is holding.
 */
final class Unavailabilities
{
    /**
     * The aways of a host that cover any part of `[$from, $to)`.
     *
     * @return Collection<int, Unavailability>
     */
    public static function coveringHost(Model $host, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return self::coveringAny([$host], $from, $to);
    }

    /**
     * The aways of a context — a ward's whole calendar closed for an evening —
     * that cover any part of `[$from, $to)`.
     *
     * @return Collection<int, Unavailability>
     */
    public static function coveringContext(Model $context, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return self::coveringAny([$context], $from, $to);
    }

    /**
     * The same question for a set of scopes at once — a pool's holders and the
     * context their times belong to — in one query, however many there are.
     *
     * @param  list<Model>  $scopes
     * @return Collection<int, Unavailability>
     */
    public static function coveringAny(array $scopes, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return self::coveringPairs(
            array_map(
                static fn (Model $scope): array => [$scope->getMorphClass(), (string) $scope->getKey()],
                $scopes,
            ),
            $from,
            $to,
        );
    }

    /**
     * The same again for scopes a caller holds as morph pairs rather than as
     * models — which is what a query that has only read `context_type` and
     * `context_id` off a row has.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return Collection<int, Unavailability>
     */
    public static function coveringPairs(array $pairs, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $byType = self::group($pairs);

        if ($byType === []) {
            return new Collection;
        }

        $start = Slot::instant($from);
        $end = Slot::instant($to);

        return Dibs::query(Unavailability::class)
            ->where(fn (Builder $scopes): Builder => self::whereScopeIn($scopes, $byType))
            ->where(fn (Builder $shapes): Builder => self::whereCouldReach($shapes, $start, $end))
            ->with('windows')
            ->get()
            // The SQL above is a sieve, not the answer: it drops the rows that
            // cannot possibly reach these instants. Whether a standing away
            // actually takes any of them out is a wall-clock question, and this
            // is where it gets asked.
            ->filter(fn (Unavailability $away): bool => $away->covers($start, $end))
            ->values();
    }

    /**
     * @param  array<string, list<string>>  $byType
     * @param  Builder<Unavailability>  $query
     * @return Builder<Unavailability>
     */
    private static function whereScopeIn(Builder $query, array $byType): Builder
    {
        $away = Dibs::make(Unavailability::class);

        foreach ($byType as $type => $ids) {
            $query->orWhere(fn (Builder $scope): Builder => $scope
                ->where($away->qualifyColumn('scope_type'), $type)
                ->whereIn($away->qualifyColumn('scope_id'), $ids));
        }

        return $query;
    }

    /**
     * Rows that could reach `[$start, $end)` at all.
     *
     * A one-off is judged exactly, in instants. A standing away is judged only
     * by the dates it runs between, with a day of slack either side: its
     * `starts_on`/`ends_on` are calendar dates on its own clock, and no zone is
     * more than a day from UTC, so a day of slack cannot lose a row. `covers()`
     * then decides the rows this lets through.
     *
     * @param  Builder<Unavailability>  $query
     * @return Builder<Unavailability>
     */
    private static function whereCouldReach(Builder $query, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $away = Dibs::make(Unavailability::class);

        return $query
            ->where(fn (Builder $once): Builder => $once
                ->where($away->qualifyColumn('kind'), UnavailabilityKind::Once->value)
                ->where($away->qualifyColumn('starts_at'), '<', $end)
                ->where($away->qualifyColumn('ends_at'), '>', $start))
            ->orWhere(fn (Builder $weekly): Builder => $weekly
                ->where($away->qualifyColumn('kind'), UnavailabilityKind::Weekly->value)
                ->where(fn (Builder $opens): Builder => $opens
                    ->whereNull($away->qualifyColumn('starts_on'))
                    ->orWhere($away->qualifyColumn('starts_on'), '<=', $end->addDay()->format('Y-m-d')))
                ->where(fn (Builder $closes): Builder => $closes
                    ->whereNull($away->qualifyColumn('ends_on'))
                    ->orWhere($away->qualifyColumn('ends_on'), '>=', $start->subDay()->format('Y-m-d'))));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, list<string>>
     */
    private static function group(array $pairs): array
    {
        $byType = [];

        foreach ($pairs as [$type, $id]) {
            if (! in_array($id, $byType[$type] ?? [], true)) {
                $byType[$type][] = $id;
            }
        }

        return $byType;
    }
}
