<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

/**
 * A series rule the package will not accept. The message is for a log; the
 * `reason` is the part a consumer keys on, because only the consumer knows how
 * to say "leave room for one appointment between the blocks" in its own words
 * and its own language (N5: the package ships no user-facing copy).
 */
final class InvalidSeries extends DibsException
{
    public const WINDOWS_REQUIRED = 'windows.required';

    public const WINDOWS_BOUNDS = 'windows.bounds';

    public const WINDOWS_OVERLAP = 'windows.overlap';

    public const WINDOWS_GAP = 'windows.gap';

    public const HOSTS_REQUIRED = 'hosts.required';

    public const ENDS_BEFORE_STARTS = 'ends_before_starts';

    public const ORDINALS_REQUIRED = 'ordinals.required';

    public const ORDINALS_FORBIDDEN = 'ordinals.forbidden';

    public const OCCURRENCE_NOT_IN_SERIES = 'occurrence.not_in_series';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
