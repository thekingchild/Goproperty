<?php

declare(strict_types=1);

namespace App\Installer\Finishing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * Runs the cache commands inside this PHP process (no exec/proc_open, so it
 * works on shared hosts that disable them, INSTALLER.md § 8).
 *
 * `config:cache` boots a second application to read fresh settings and
 * leaves the facades and the global container pointing at it. That does not
 * matter at the end of a command, but the web wizard keeps working in the
 * same request (audit entry, console handover, finish screen), so this
 * application is put back afterwards.
 */
final class ArtisanOptimizer implements ApplicationOptimizer
{
    public const array COMMANDS = ['config:cache', 'route:cache', 'view:cache', 'event:cache'];

    public function __construct(
        private readonly Kernel $artisan,
        private readonly Application $app,
    ) {}

    public function cache(): void
    {
        try {
            foreach (self::COMMANDS as $command) {
                if ($this->artisan->call($command) !== 0) {
                    throw new RuntimeException("php artisan {$command} failed.");
                }
            }
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
            Container::setInstance($this->app);
        }
    }
}
