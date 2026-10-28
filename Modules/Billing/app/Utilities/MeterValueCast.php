<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * `decimal(15,3)` meter columns as MeterValue. Writes accept only a
 * MeterValue (or null), so a float never reaches the column.
 *
 * @implements CastsAttributes<MeterValue, mixed>
 */
final class MeterValueCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?MeterValue
    {
        if ($value === null) {
            return null;
        }

        $parsed = is_string($value) || is_int($value) ? MeterValue::parse((string) $value) : null;

        if ($parsed === null) {
            throw new InvalidArgumentException("{$key} does not hold a meter value.");
        }

        return $parsed;
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof MeterValue) {
            throw new InvalidArgumentException("Set {$key} with ".MeterValue::class.', not '.get_debug_type($value).'.');
        }

        return $value->toDecimalString();
    }
}
