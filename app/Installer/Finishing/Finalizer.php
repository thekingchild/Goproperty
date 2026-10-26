<?php

declare(strict_types=1);

namespace App\Installer\Finishing;

use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the installer for good (INSTALLER.md § 5, "Finish"), in this order:
 *
 * 1. checkpoint → cleanup_pending, so if anything below fails the installer
 *    is already closed and recovery is via the CLI (§ 9);
 * 2. ADMIN_PATH = gp-control-xxxxxx, sessions and cache on the database;
 * 3. setup token, unlock challenge and grant deleted;
 * 4. installed.lock written and the database row set to finalized;
 * 5. config, route, view and event caches built;
 * 6. recorded in install.log (the audit log takes over in P00-11).
 *
 * Signing the owner in to the console (step 7) belongs to the wizard. Safe to
 * run again after a failure: an existing unique ADMIN_PATH is kept.
 */
final class Finalizer
{
    /** Installer files removed at step 3. */
    public const array SECRETS = ['setup_token', 'unlock_challenge', 'unlock_file', 'grant', 'attempts'];

    public function __construct(
        private readonly InstallationMarkers $markers,
        private readonly InstallationRecord $record,
        private readonly DatabaseManager $db,
        private readonly Config $config,
        private readonly ApplicationOptimizer $optimizer,
    ) {}

    public function finalize(EnvironmentFile $environment, string $version): FinalizeResult
    {
        $this->closeCheckpoint();

        $current = $environment->get('ADMIN_PATH') ?? '';
        $adminPath = AdminPath::isUnique($current) ? $current : AdminPath::generate();

        $environment->set([
            'ADMIN_PATH' => $adminPath,
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
        ]);

        foreach (self::SECRETS as $name) {
            $path = $this->markers->path($name);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->markers->writeLock($version);
        $this->record->record($this->db->connection(), InstallStage::Finalized, $version);
        $this->markers->checkpoint()->advance(InstallStage::Finalized);

        $warnings = [];

        // A site without caches still works; finishing must not fail here.
        try {
            $this->optimizer->cache();
        } catch (Throwable $exception) {
            $warnings[] = 'The caches could not be built ('.$exception->getMessage().'). Run php artisan optimize later.';
        }

        Log::channel('install')->info('Installation finalized', ['version' => $version, 'warnings' => count($warnings)]);

        return new FinalizeResult($adminPath, $warnings);
    }

    private function closeCheckpoint(): void
    {
        $checkpoint = $this->markers->checkpoint();

        if ($checkpoint->read()->fingerprint !== null) {
            $checkpoint->advance(InstallStage::CleanupPending);

            return;
        }

        // The command-line install (P00-06) may reach here without one.
        $name = $this->config->get('database.default');
        $connection = $this->config->get('database.connections.'.(is_string($name) ? $name : 'mysql'));

        $checkpoint->write(InstallStage::CleanupPending, DatabaseSettings::fromConnection(is_array($connection) ? $connection : []));
    }
}
