<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

final class InvalidTransition extends DibsException
{
    public static function for(Model $model, BackedEnum $from, BackedEnum $to): self
    {
        return new self(sprintf(
            '%s %s cannot move from %s to %s.',
            class_basename($model),
            $model->getKey(),
            $from->value,
            $to->value,
        ));
    }
}
