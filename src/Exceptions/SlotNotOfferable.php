<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

use RobinsonRyan\Dibs\Models\Slot;

final class SlotNotOfferable extends DibsException
{
    public static function for(Slot $slot, string $reason): self
    {
        return new self(sprintf('Slot %s cannot be offered: %s', $slot->getKey(), $reason));
    }
}
