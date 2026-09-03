<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns a pool entry into the people it stands for at a moment.
 *
 * A pool row does not have to name a person. A consumer may pool a *position* —
 * a calling, a rota, a desk — and mean "whoever holds it then", so that a set of
 * times opened for months ahead survives the holder changing. This contract is
 * the one place the package asks that question, and it asks it at a stated
 * moment because the answer moves.
 *
 * An empty collection means the entry stands for nobody just now: it is vacant,
 * contributes no capacity, and cannot make a slot bookable. Several models mean
 * the entry has several seats and contributes several. The default binding is
 * identity, so a consumer that pools people directly never notices this exists.
 *
 * @see \RobinsonRyan\Dibs\Support\IdentityHostResolver
 */
interface HostResolver
{
    /**
     * @return Collection<int, Model>
     */
    public function resolve(Model $host, CarbonInterface $at): Collection;
}
