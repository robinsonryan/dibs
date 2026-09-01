<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Dibs\Concerns\HasUuidPrimaryKey;

/**
 * Consumer stand-in: a uuid-keyed model the package attaches to via morphs.
 *
 * @property string $id
 * @property string $name
 */
final class Organization extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'fixture_organizations';

    protected $guarded = [];
}
