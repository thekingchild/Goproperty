<?php

declare(strict_types=1);

namespace App\Installer;

/** The release version from the package's VERSION file ("dev" in a checkout). */
final class AppVersion
{
    public static function current(): string
    {
        $file = base_path('VERSION');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : 'dev';
    }
}
