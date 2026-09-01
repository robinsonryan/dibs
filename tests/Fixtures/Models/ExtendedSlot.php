<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Tests\Fixtures\Models;

use RobinsonRyan\Dibs\Models\Slot;

/**
 * A consumer's extended model, substituted via config('dibs.models').
 */
final class ExtendedSlot extends Slot
{
    public function shout(): string
    {
        return 'extended';
    }
}
