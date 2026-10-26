<?php

declare(strict_types=1);

namespace App\Installer\Finishing;

/**
 * The console path rules (PLATFORM_CONSOLE.md § 10): lowercase letters,
 * numbers and hyphens, 8–40 characters, never a reserved word. The installer
 * gives every installation its own `gp-control-xxxxxx`.
 */
final class AdminPath
{
    public const string DEFAULT = 'gp-control';

    /** Top-level paths the application already uses. */
    public const array RESERVED = [
        'admin', 'login', 'o', 'portal', 'owner', 'pay', 'verify', 'api', 'webhooks', 'storage', 'build',
        'install', 'up', 'livewire', 'brand', 'dashboard', 'settings', 'organizations',
    ];

    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public static function generate(): string
    {
        $suffix = '';

        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::DEFAULT.'-'.$suffix;
    }

    public static function isValid(string $path): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{6,38})[a-z0-9]$/D', $path) === 1
            && ! in_array($path, self::RESERVED, true);
    }

    /** A valid path that is not the shared default. */
    public static function isUnique(string $path): bool
    {
        return self::isValid($path) && $path !== self::DEFAULT;
    }
}
