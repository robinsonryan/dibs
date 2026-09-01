<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class DeletionRefused extends DibsException
{
    public static function for(Model $model, string $reason): self
    {
        return new self(sprintf('%s %s cannot be deleted: %s', class_basename($model), $model->getKey(), $reason));
    }
}
