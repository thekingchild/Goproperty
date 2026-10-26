<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\EnvironmentFile;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationSteps;
use App\Installer\InstallStage;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recovery (INSTALLER.md § 9): completes a finish that stopped after the
 * checkpoint said `cleanup_pending`. The installer is already closed then,
 * so neither the wizard nor `goproperty:install` can do it.
 */
final class FinishInstallCommand extends Command
{
    protected $signature = 'goproperty:finish-install';

    protected $description = 'Complete an install whose final step was interrupted (recovery)';

    public function handle(InstallationMarkers $markers, InstallationSteps $steps, EnvironmentFile $environment): int
    {
        $checkpoint = $markers->checkpoint()->read();

        if ($checkpoint->stage !== InstallStage::CleanupPending) {
            $this->components->error('Nothing to finish: this command only completes an install whose checkpoint says cleanup_pending. See docs/runbooks/install-recovery.md.');

            return self::FAILURE;
        }

        try {
            $result = $steps->finalize($environment);
        } catch (Throwable $exception) {
            $this->components->error('The finish failed again: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        $url = config('app.url');
        $this->components->info('The install is finished.');
        $this->components->twoColumnDetail('Console', (is_string($url) ? $url : '').'/'.$result->adminPath);

        return self::SUCCESS;
    }
}
