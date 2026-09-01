<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Support;

final class TablePrefixer
{
    public static function prefix(string $table): string
    {
        $prefix = config('dibs.table_prefix', 'dibs_');

        return (is_string($prefix) ? $prefix : 'dibs_').$table;
    }
}
