<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use App\Installer\AppVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * The App area of the operations dashboard (PLATFORM_CONSOLE.md § 2): the
 * release running, its environment, maintenance mode, migrations not run
 * yet and exceptions reported in the last 15 minutes and hour (counted by
 * ErrorCounter). Uptime, p95 response time and an error rate per request
 * need an outside monitor (Sentry or a load balancer) and are not shown;
 * nor is a maintenance mode switch (docs/plans/P08-subtasks.md default 46).
 */
final readonly class AppHealth
{
    public function __construct(private Application $app, private ErrorCounter $errors) {}

    /** @return array<string, string|int|bool|null> */
    public function figures(CarbonImmutable $now): array
    {
        try {
            $pending = $this->pendingMigrations();
        } catch (Throwable) {
            $pending = null;
        }

        return [
            'version' => AppVersion::current(),
            'environment' => (string) $this->app->environment(),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'hosting' => (string) config('hosting.profile'),
            'maintenance' => $this->app->isDownForMaintenance(),
            'pending_migrations' => $pending,
            'errors_15m' => $this->errors->lastMinutes(15, $now),
            'errors_1h' => $this->errors->lastMinutes(60, $now),
        ];
    }

    /**
     * Counted only, from the same files and `migrations` table as the
     * installer; nothing here runs them. Running them from the console is
     * the super-admins' upgrade step only (Operations → Version,
     * INSTALLER.md § 10).
     */
    private function pendingMigrations(): int
    {
        $migrator = $this->app->make(Migrator::class);

        if (! $migrator->repositoryExists()) {
            return 0;
        }

        $files = $migrator->getMigrationFiles(array_values(array_unique([database_path('migrations'), ...$migrator->paths()])));

        return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }
}
