<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The owning scope a record belongs to — a tenant, an organization — stored as
 * a `context_type`/`context_id` morph and always nullable, because a
 * single-tenant consumer has nothing to own its records.
 *
 * Availabilities, offers and bookings each carry one, and each carried an
 * identical copy of this pair of methods before they were pulled up here.
 */
trait HasContext
{
    /**
     * The owning scope (a tenant, an organization); null for single-tenant consumers.
     *
     * @return MorphTo<Model, $this>
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * Records owned by the given context (tenant / organisation).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForContext(Builder $query, Model $context): Builder
    {
        return $query
            ->where($this->qualifyColumn('context_type'), $context->getMorphClass())
            ->where($this->qualifyColumn('context_id'), (string) $context->getKey());
    }
}
