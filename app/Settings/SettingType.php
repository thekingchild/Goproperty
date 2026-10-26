<?php

declare(strict_types=1);

namespace App\Settings;

enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';

    /** A JSON object or list (e.g. colour palettes). */
    case Json = 'json';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value),
            self::Integer => is_int($value),
            self::Boolean => is_bool($value),
            self::Json => is_array($value),
        };
    }
}
