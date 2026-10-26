<?php

declare(strict_types=1);

namespace App\Installer;

use Illuminate\Encryption\Encrypter;

/**
 * Whether APP_KEY can encrypt. Checked without building the encrypter, which
 * would throw (and log) "No application encryption key has been specified".
 */
final class ApplicationKey
{
    public static function usable(mixed $key, mixed $cipher): bool
    {
        if (! is_string($key) || $key === '' || ! is_string($cipher)) {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);

            if ($key === false) {
                return false;
            }
        }

        return Encrypter::supported($key, $cipher);
    }

    public static function configured(): bool
    {
        return self::usable(config('app.key'), config('app.cipher'));
    }
}
