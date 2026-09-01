<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

use RobinsonRyan\Dibs\Models\Slot;

final class SlotUnavailable extends DibsException
{
    public static function for(Slot $slot, string $reason): self
    {
        return new self(sprintf('Slot %s cannot be booked: %s', $slot->getKey(), $reason));
    }
}
