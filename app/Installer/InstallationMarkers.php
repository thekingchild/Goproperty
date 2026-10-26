<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Database\DatabaseSettings;
use App\Installer\Support\AtomicFile;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reads and writes the three installation markers (INSTALLER.md § 3):
 *
 * - installer **closed** if any marker says the install finished, or the
 *   checkpoint cannot be verified (fail closed);
 * - site **open** only when the lock file and the database row both say
 *   finalized.
 *
 * Before APP_KEY exists nothing here touches the database.
 */
final class InstallationMarkers
{
    private const string PROBE_CONNECTION = 'installer_markers';

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly InstallationRecord $record,
    ) {}

    public function installerOpen(): bool
    {
        if ($this->lockExists()) {
            return false;
        }

        $checkpoint = $this->checkpoint()->read();

        if ($checkpoint->saysFinished()) {
            return false;
        }

        if (! ApplicationKey::configured()) {
            return true;
        }

        return match ($this->databaseMarker()) {
            DatabaseMarker::Finalized => false,
            // Once the installer has recorded a database, not being able to
            // read it is no reason to reopen (recovery is via the CLI, § 9).
            DatabaseMarker::Unreachable => ! $checkpoint->exists,
            DatabaseMarker::Absent, DatabaseMarker::InProgress => true,
        };
    }

    public function siteState(): SiteState
    {
        if (! $this->lockExists()) {
            return SiteState::NotInstalled;
        }

        try {
            $stage = $this->record->stage($this->db->connection());
        } catch (Throwable) {
            return SiteState::Unavailable;
        }

        return $stage === InstallStage::Finalized ? SiteState::Open : SiteState::NotInstalled;
    }

    /**
     * The database marker, read with a short timeout so a wrong host in a
     * half-configured `.env` never hangs the installer.
     */
    public function databaseMarker(): DatabaseMarker
    {
        $configured = $this->config->get('installer.marker_connection');

        try {
            $stage = $this->record->stage(is_string($configured) ? $this->db->connection($configured) : $this->probeConnection());
        } catch (Throwable) {
            return DatabaseMarker::Unreachable;
        } finally {
            if (! is_string($configured)) {
                $this->db->purge(self::PROBE_CONNECTION);
            }
        }

        return match (true) {
            $stage === null => DatabaseMarker::Absent,
            $stage === InstallStage::Finalized => DatabaseMarker::Finalized,
            default => DatabaseMarker::InProgress,
        };
    }

    public function checkpoint(): Checkpoint
    {
        $key = $this->config->get('app.key');

        return new Checkpoint($this->path('checkpoint'), is_string($key) ? $key : '');
    }

    public function lockExists(): bool
    {
        return is_file($this->path('lock'));
    }

    public function writeLock(string $version): void
    {
        AtomicFile::write($this->path('lock'), (string) json_encode([
            'version' => $version,
            'finalized_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT)."\n", 0644);
    }

    public function path(string $name): string
    {
        $path = $this->config->get("installer.paths.{$name}");

        return is_string($path) ? $path : storage_path("app/private/install.{$name}");
    }

    private function probeConnection(): ConnectionInterface
    {
        $name = $this->config->get('database.default');
        $base = $this->config->get('database.connections.'.(is_string($name) ? $name : 'mysql'));
        $base = is_array($base) ? $base : [];
        $timeout = $this->config->get('installer.database_timeout', 3);

        $this->config->set(
            'database.connections.'.self::PROBE_CONNECTION,
            DatabaseSettings::fromConnection($base)->connectionConfig($base, is_numeric($timeout) ? (int) $timeout : 3),
        );

        return $this->db->connection(self::PROBE_CONNECTION);
    }
}
