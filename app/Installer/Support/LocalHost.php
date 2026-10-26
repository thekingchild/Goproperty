<?php

declare(strict_types=1);

namespace App\Installer\Support;

/** Hosts of a local test copy, which may run without HTTPS. */
final class LocalHost
{
    public static function is(string $host): bool
    {
        return preg_match('/^(localhost|127\.0\.0\.1|\[?::1\]?|.+\.test|.+\.localhost)$/D', strtolower($host)) === 1;
    }
}
