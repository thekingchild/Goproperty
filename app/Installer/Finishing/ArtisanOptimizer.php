<?php

declare(strict_types=1);

namespace App\Installer\Finishing;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

/**
 * Runs the cache commands inside this PHP process (no exec/proc_open, so it
 * works on shared hosts that disable them, INSTALLER.md § 8).
 */
final class ArtisanOptimizer implements ApplicationOptimizer
{
    public const array COMMANDS = ['config:cache', 'route:cache', 'view:cache', 'event:cache'];

    public function __construct(private readonly Kernel $artisan) {}

    public function cache(): void
    {
        foreach (self::COMMANDS as $command) {
            if ($this->artisan->call($command) !== 0) {
                throw new RuntimeException("php artisan {$command} failed.");
            }
        }
    }
}
