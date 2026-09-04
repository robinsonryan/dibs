<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Enums;

/**
 * The two shapes an away has: a span, or weekday windows that repeat until
 * somebody removes them. Nothing else — an ordinal-month away is a rule nobody
 * asked for, and two aways say the same thing more plainly.
 */
enum UnavailabilityKind: string
{
    case Once = 'once';

    case Weekly = 'weekly';
}
