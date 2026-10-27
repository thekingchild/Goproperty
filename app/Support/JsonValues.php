<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Compares values that went through a JSON column. MySQL's JSON type stores
 * object keys in its own order (MariaDB keeps the written order), so a map
 * read back can differ in key order only. Lists keep their order.
 */
final class JsonValues
{
    public static function same(mixed $a, mixed $b): bool
    {
        return self::normalise($a) === self::normalise($b);
    }

    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::normalise(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
