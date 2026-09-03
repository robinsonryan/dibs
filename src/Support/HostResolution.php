<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Contracts\HostResolver;
use RobinsonRyan\Dibs\Models\Slot;

/**
 * The bound `HostResolver`, asked at most once per question inside one read.
 *
 * A resolver is a consumer's code and usually a query — ccstake's asks who
 * holds a calling in a ward — so how often the package calls it is part of the
 * package's cost, not the consumer's. Without this, one
 * `bookable(requireFreeHost: true)` over a 90-day horizon called it once per
 * pool row per availability: sixteen days times three pooled positions was 48
 * calls for one listing, and the "fixed number of queries" claim held only for
 * the identity resolver, which queries nothing.
 *
 * The question is `(entry, context, date)`: the same pooled position, asked
 * about by the same context, on the same day, has one answer. Two entries in
 * one availability's pool that name the same position collapse to one call, and
 * so do the availabilities of one date. **Date**, not instant: who holds a
 * position is a fact about a day — that is what makes a set of times opened
 * months ahead survive the holder changing — and the alternative was a distinct
 * call for every block of every day. Within one read the answer for a day is
 * therefore asked once; across reads nothing is remembered, because a memo that
 * outlived the request would be a cache with no way to say a calling had moved.
 *
 * One of these per `bookable(requireFreeHost:)` / `capacityFor()` /
 * `freeHosts()` / `freeHolders()` call. It is deliberately not a singleton.
 */
final class HostResolution
{
    private readonly HostResolver $resolver;

    /**
     * @var array<string, list<Model>>
     */
    private array $answers = [];

    public function __construct(?HostResolver $resolver = null)
    {
        $this->resolver = $resolver ?? app(HostResolver::class);
    }

    /**
     * The people this pool entry stands for, at that moment, in that context.
     *
     * @return list<Model>
     */
    public function holders(Model $host, CarbonInterface $at, ?Model $context = null): array
    {
        $key = implode('|', [
            $host->getMorphClass(),
            (string) $host->getKey(),
            $context?->getMorphClass() ?? '-',
            $context instanceof Model ? (string) $context->getKey() : '-',
            // Normalised through the package's own UTC reading (D10), so two
            // callers naming the same instant differently ask once.
            Slot::instant($at)->format('Y-m-d'),
        ]);

        if (! isset($this->answers[$key])) {
            /** @var list<Model> $holders */
            $holders = array_values($this->resolver->resolve($host, $at, $context)->all());

            $this->answers[$key] = $holders;
        }

        return $this->answers[$key];
    }
}
