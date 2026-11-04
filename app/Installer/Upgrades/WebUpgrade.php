<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\Migrations\BatchedMigrator;
use App\Models\PlatformStaff;
use App\Platform\Operations\OperationsAudit;
use Database\Seeders\InstallSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The console's upgrade step for hosts without SSH (INSTALLER.md § 10,
 * step 6): after the new release's files are in place, each request does
 * one piece of work, so it stays well inside shared hosting's 30-second
 * limit, and the page posts the next one by itself:
 *
 * 1. a batch of pending migrations (the installer's own BatchedMigrator:
 *    5 migrations or 20 seconds, resumed from the `migrations` table);
 * 2. once none is pending, the idempotent install seeders (roles gain the
 *    release's new permissions; the database records the release) and the
 *    config, route, view and event caches rebuilt.
 *
 * It only ever runs migrations forward: no reset, no rollback. Rollback is
 * the folder swap plus the pre-upgrade backup (docs/runbooks/upgrade.md).
 *
 * Refused when nothing is pending, and while another request holds the
 * upgrade lock. The first piece of work switches maintenance mode on (the
 * upgrade kind: the console stays open) unless it already is; the last
 * switches it off again. A failure leaves the site in maintenance mode.
 * Who may run it (`system.upgrade`, a fresh password and code) is checked
 * by the console before it gets here. Every piece of work is audited
 * (log `operations`).
 */
final readonly class WebUpgrade
{
    public function __construct(
        private PendingUpgrade $pending,
        private BatchedMigrator $migrator,
        private UpgradeLock $lock,
        private UpgradeMaintenance $maintenance,
        private ApplicationOptimizer $optimizer,
        private OperationsAudit $audit,
        private Application $app,
    ) {}

    /** @throws UpgradeRefused */
    public function step(PlatformStaff $staff): UpgradeStep
    {
        return $this->lock->run(function () use ($staff): UpgradeStep {
            $state = $this->pending->state();

            if (! $state->pending()) {
                throw new UpgradeRefused('Nothing to run: every migration has run and the install seeders ran for '.$state->codeVersion.'.');
            }

            $started = $this->maintenance->start();

            if ($started) {
                $this->audit->record('upgrade.maintenance_on', 'Maintenance mode on for the upgrade to '.$state->codeVersion, causer: $staff, properties: ['by' => 'upgrade step']);
            }

            return $state->migrations->finished()
                ? $this->finish($staff, $state, $started)
                : $this->migrate($staff, $state, $started);
        });
    }

    private function migrate(PlatformStaff $staff, UpgradeState $state, bool $started): UpgradeStep
    {
        $batch = $this->migrator->runBatch();
        $after = $this->pending->state();
        $properties = ['from' => $state->databaseVersion, 'to' => $state->codeVersion, 'ran' => $batch->ran, 'done' => $batch->progress->done, 'total' => $batch->progress->total];

        if ($batch->failed()) {
            $this->audit->record('upgrade.migration_failed', 'Upgrade migration '.$batch->failedMigration.' failed', causer: $staff, properties: $properties + ['migration' => $batch->failedMigration, 'error' => $batch->error]);

            return new UpgradeStep(UpgradeStepKind::MigrationFailed, $after, $batch->ran, $batch->failedMigration, $batch->error, maintenanceStarted: $started);
        }

        $this->audit->record('upgrade.migrated', 'Upgrade migrations ran: '.$batch->progress->label(), causer: $staff, properties: $properties);

        return new UpgradeStep(UpgradeStepKind::Migrated, $after, $batch->ran, maintenanceStarted: $started);
    }

    private function finish(PlatformStaff $staff, UpgradeState $state, bool $started): UpgradeStep
    {
        try {
            $seeder = $this->app->make(InstallSeeder::class);
            $seeder->setContainer($this->app)->__invoke();
            // Compiled views copied over with storage/ are older than nothing
            // in the new release's files, so Laravel would keep using them.
            $this->optimizer->cache();
        } catch (Throwable $exception) {
            $error = trim((string) preg_replace('/\s*\(Connection: .*$/s', '', $exception->getMessage()));
            Log::error('The upgrade step could not finish', ['error' => $error]);
            $this->audit->record('upgrade.finish_failed', 'The upgrade to '.$state->codeVersion.' could not finish', causer: $staff, properties: ['to' => $state->codeVersion, 'error' => $error]);

            return new UpgradeStep(UpgradeStepKind::FinishFailed, $this->pending->state(), error: $error, maintenanceStarted: $started);
        }

        $ended = $this->maintenance->end();
        $this->audit->record('upgrade.finished', 'Upgraded to '.$state->codeVersion, causer: $staff, properties: [
            'from' => $state->databaseVersion, 'to' => $state->codeVersion, 'migrations' => $state->migrations->total, 'maintenance_ended' => $ended,
        ]);

        return new UpgradeStep(UpgradeStepKind::Finished, $this->pending->state(), maintenanceStarted: $started, maintenanceEnded: $ended);
    }
}
