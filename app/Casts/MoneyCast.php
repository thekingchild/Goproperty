<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * `*_kobo` columns as Money. Writes accept only Money (or null), so a naira
 * number can never be stored as kobo by mistake.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)) {
            return Money::kobo((int) $value);
        }

        throw new InvalidArgumentException("{$key} does not hold a kobo amount.");
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("Set {$key} with App\\Support\\Money, not ".get_debug_type($value).'.');
        }

        return $value->kobo;
    }
}
