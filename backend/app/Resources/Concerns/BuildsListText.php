<?php

namespace App\Resources\Concerns;

trait BuildsListText
{
    /** @param  array<int, array<string, mixed>>  $items */
    public static function toListMultiple(array $items): string
    {
        return implode("\n", array_map(self::toList(...), $items));
    }
}
