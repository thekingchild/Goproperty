<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use App\Installer\AppVersion;
use App\Installer\InstallationRecord;
use App\Installer\Migrations\BatchedMigrator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * Reads the upgrade state: the same migration files and `migrations` table
 * as the installer's batches, and the version recorded in the
 * `installation_state` row. Read-only.
 */
final readonly class PendingUpgrade
{
    public function __construct(
        private BatchedMigrator $migrator,
        private DatabaseManager $db,
    ) {}

    public function state(): UpgradeState
    {
        return new UpgradeState(AppVersion::current(), $this->databaseVersion(), $this->migrator->progress());
    }

    private function databaseVersion(): ?string
    {
        try {
            $version = $this->db->connection()->table(InstallationRecord::TABLE)->where('id', 1)->value('version');
        } catch (QueryException $exception) {
            if (InstallationRecord::isMissingTable($exception)) {
                return null;
            }

            throw $exception;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }
}
