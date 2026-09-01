<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolves the package's model classes through the `dibs.models` class-map,
 * so a consumer's extended model is used everywhere the package itself
 * relates to or queries one of its own models.
 */
final class Dibs
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return class-string<TModel>
     */
    public static function model(string $model): string
    {
        $map = config('dibs.models', []);
        $configured = is_array($map) ? ($map[$model] ?? $model) : $model;

        if (! is_string($configured) || ! is_a($configured, $model, true)) {
            throw new InvalidArgumentException(sprintf(
                'dibs.models[%s] must name a class extending %s.',
                $model,
                $model,
            ));
        }

        return $configured;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function make(string $model): Model
    {
        $class = self::model($model);

        return new $class;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function query(string $model): Builder
    {
        /** @var Builder<TModel> $query */
        $query = self::make($model)->newQuery();

        return $query;
    }
}
