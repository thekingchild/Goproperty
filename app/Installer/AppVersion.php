<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The release version from the package's VERSION file ("dev" in a checkout
 * or a deploy archive, which carry none). Tests point
 * `installer.paths.version` at a file of their own.
 */
final class AppVersion
{
    public const string DEVELOPMENT = 'dev';

    public static function current(): string
    {
        $configured = config('installer.paths.version');
        $file = is_string($configured) && $configured !== '' ? $configured : base_path('VERSION');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : self::DEVELOPMENT;
    }

    /** A real release number (not "dev"), so it can be compared with another. */
    public static function isRelease(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    /** -1, 0 or 1, like version_compare, with a leading "v" ignored. */
    public static function compare(string $a, string $b): int
    {
        return version_compare(ltrim($a, 'v'), ltrim($b, 'v'));
    }
}
