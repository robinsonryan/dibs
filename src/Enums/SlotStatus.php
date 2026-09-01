<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

enum SlotStatus: string
{
    case Open = 'open';
    case Held = 'held';
    case Booked = 'booked';
}
