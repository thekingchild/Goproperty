<?php

declare(strict_types=1);

namespace App\Installer;

use Closure;

/**
 * The one cron line for this server (INSTALLER.md § 5, finish screen). The
 * PHP **CLI** binary is never PHP_BINARY itself under the web server: that is
 * often php-fpm or lsphp, which cannot run artisan (a SocietyFlow bug). It is,
 * in order: the CLI beside the PHP serving the site (same build, so the same
 * extensions: on CloudLinux that tells alt-php from ea-php), the panel CLI
 * this command already runs on, then the panels' known paths.
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
        $current = $this->currentBinary ?? PHP_BINARY;
        $isCli = preg_match('/^php(?:\d[\d.]*)?$/', basename($current)) === 1;

        if (! $isCli && ($sibling = $this->cliBeside($current)) !== null) {
            return $sibling;
        }

        if ($isCli && in_array($current, self::KNOWN_BINARIES, true) && ($this->isExecutable)($current)) {
            return $current;
        }

        foreach (self::KNOWN_BINARIES as $path) {
            if (($this->isExecutable)($path)) {
                return $path;
            }
        }

        // Trust PHP_BINARY only when it is a CLI binary.
        return $isCli ? $current : 'php';
    }

    /**
     * The CLI of the build a web server binary belongs to: lsphp sits beside
     * it (/opt/alt/php84/usr/bin/), php-fpm in the sibling sbin/
     * (/opt/cpanel/ea-php84/root/usr/sbin/, /opt/plesk/php/8.4/sbin/).
     */
    private function cliBeside(string $binary): ?string
    {
        if (preg_match('/^(?:lsphp|php-fpm|php-cgi)[\d.]*$/', basename($binary)) !== 1) {
            return null;
        }

        $folder = dirname($binary);

        foreach ([$folder.'/php', dirname($folder).'/bin/php'] as $candidate) {
            if (($this->isExecutable)($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function line(string $basePath): string
    {
        return '* * * * * '.self::quote($this->phpBinary()).' '.self::quote(self::stablePath($basePath).'/artisan').' schedule:run >> /dev/null 2>&1';
    }

    /**
     * In the deploy layout (releases/<name>/ beside shared/), cron must run
     * current/artisan: release folders are replaced and pruned on deploys
     * (docs/runbooks/shared-hosting.md § 2).
     */
    public static function stablePath(string $basePath): string
    {
        $basePath = rtrim($basePath, '/');
        $releases = dirname($basePath);
        $app = dirname($releases);

        return basename($releases) === 'releases' && is_dir($app.'/shared') ? $app.'/current' : $basePath;
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.\/\-]+$/D', $value) === 1 ? $value : escapeshellarg($value);
    }
}
