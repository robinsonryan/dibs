<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Concerns;

/**
 * Configures model primary key settings for UUID7 identifiers.
 *
 * The database (PostgreSQL 18+) generates UUID7 values via its native uuidv7()
 * function as the column default — Laravel never generates them.
 *
 * `$keyType = 'string'` tells Eloquent the key is a UUID rather than an integer.
 * `$incrementing = true` does NOT mean "auto-increment"; in Eloquent it means
 * "the database assigns the key on insert", which is exactly what a uuidv7()
 * column default does. It makes Eloquent use `insertGetId()`, so the INSERT is
 * compiled with PostgreSQL's `returning "id"` clause and the generated UUID is
 * hydrated back onto the model. Without it the model would come back from
 * `create()` with a null key.
 */
trait HasUuidPrimaryKey
{
    public function initializeHasUuidPrimaryKey(): void
    {
        $this->incrementing = true;
        $this->keyType = 'string';
    }
}
