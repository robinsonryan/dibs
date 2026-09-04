<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

/**
 * An away the package will not accept. The message is for a log; the `reason`
 * is the part a consumer keys on, because only the consumer knows how to say
 * "the time you are away until has to come after the time it starts" in its own
 * words and its own language (N5: the package ships no user-facing copy).
 */
final class InvalidUnavailability extends DibsException
{
    public const SPAN_REQUIRED = 'span.required';

    public const SPAN_INVERTED = 'span.inverted';

    public const SPAN_FORBIDDEN = 'span.forbidden';

    public const WINDOWS_REQUIRED = 'windows.required';

    public const WINDOWS_FORBIDDEN = 'windows.forbidden';

    public const WINDOWS_BOUNDS = 'windows.bounds';

    public const STARTS_ON_REQUIRED = 'starts_on.required';

    public const ENDS_BEFORE_STARTS = 'ends_before_starts';

    public const TIMEZONE_INVALID = 'timezone.invalid';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
