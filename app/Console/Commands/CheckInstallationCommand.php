<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\Health\HealthCheck;
use App\Installer\Health\InstallationHealth;
use App\Installer\Requirements\CheckStatus;
use Illuminate\Console\Command;

/**
 * Checks an installed site (INSTALLER.md § 7): server, schema, markers,
 * settings and scheduled work. Exits 1 when anything fails, so it suits
 * deploy scripts and monitoring.
 */
final class CheckInstallationCommand extends Command
{
    protected $signature = 'goproperty:check-installation {--json : Print the results as JSON}';

    protected $description = 'Check this installation: server, database schema, markers, settings and scheduled work';

    public function handle(InstallationHealth $health): int
    {
        $checks = $health->check();
        $failed = array_filter($checks, fn (HealthCheck $check): bool => $check->status === CheckStatus::Fail);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'passed' => $failed === [],
                'checks' => array_map(fn (HealthCheck $check): array => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $mark = match ($check->status) {
                CheckStatus::Pass => '<fg=green>✓</>',
                CheckStatus::Warn => '<fg=yellow>!</>',
                CheckStatus::Fail => '<fg=red>✗</>',
            };

            $this->components->twoColumnDetail("{$mark} {$check->group}: {$check->label}", $check->detail);
        }

        $this->newLine();

        if ($failed !== []) {
            $this->components->error(count($failed).' '.(count($failed) === 1 ? 'check' : 'checks').' failed.');

            return self::FAILURE;
        }

        $this->components->info('The installation is healthy.');

        return self::SUCCESS;
    }
}
