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

    /**
     * Re-read a model's row `FOR UPDATE` inside the caller's transaction, through
     * the class-map. Every state transition in this package is decided from the
     * row this returns, never from a snapshot the caller happens to hold: two
     * sessions racing for the same row queue up instead of both reading `pending`.
     *
     * Returns null when the row is no longer there — each action says for itself
     * what that means.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel|null
     */
    public static function lock(Model $model): ?Model
    {
        return self::query($model::class)
            ->whereKey($model->getKey())
            ->lockForUpdate()
            ->first();
    }
}
