<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Dibs\Contracts\HostResolver;

/**
 * The default: a pooled host stands for itself, at every moment. Bound in the
 * service provider with `bind()`, so a consumer that pools positions rather
 * than people replaces it with one line of its own.
 */
final class IdentityHostResolver implements HostResolver
{
    /**
     * @return Collection<int, Model>
     */
    public function resolve(Model $host, CarbonInterface $at, ?Model $context = null): Collection
    {
        return new Collection([$host]);
    }
}
