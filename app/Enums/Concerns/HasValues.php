<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * For backed enums: every value, for validation rules (`Rule::in(X::values())`)
 * and database checks.
 */
trait HasValues
{
    /** @return list<int|string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
