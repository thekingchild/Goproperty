<?php

declare(strict_types=1);

namespace App\Installer;

use Closure;

/**
 * The one cron line for this server (INSTALLER.md § 5, finish screen). The
 * PHP **CLI** binary is found from the panels' known paths, not PHP_BINARY:
 * under the web server that is often php-fpm or lsphp, which cannot run
 * artisan (a SocietyFlow bug).
 */
final class CronLine
{
    /** Checked in order; the first that exists wins. */
    public const array KNOWN_BINARIES = [
        'cPanel' => '/opt/cpanel/ea-php84/root/usr/bin/php',
        'CloudLinux' => '/opt/alt/php84/usr/bin/php',
        'Plesk' => '/opt/plesk/php/8.4/bin/php',
    ];

    /** @var Closure(string): bool */
    private readonly Closure $isExecutable;

    /**
     * @param  (Closure(string): bool)|null  $isExecutable
     */
    public function __construct(?Closure $isExecutable = null, private readonly ?string $currentBinary = null)
    {
        $this->isExecutable = $isExecutable ?? static fn (string $path): bool => @is_executable($path);
    }

    public function phpBinary(): string
    {
        foreach (self::KNOWN_BINARIES as $path) {
            if (($this->isExecutable)($path)) {
                return $path;
            }
        }

        // Trust PHP_BINARY only when it is a CLI binary.
        $current = $this->currentBinary ?? (PHP_SAPI === 'cli' ? PHP_BINARY : '');

        return preg_match('/^php(?:\d[\d.]*)?$/', basename($current)) === 1 ? $current : 'php';
    }

    public function line(string $basePath): string
    {
        return '* * * * * '.self::quote($this->phpBinary()).' '.self::quote(rtrim($basePath, '/').'/artisan').' schedule:run >> /dev/null 2>&1';
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.\/\-]+$/D', $value) === 1 ? $value : escapeshellarg($value);
    }
}
