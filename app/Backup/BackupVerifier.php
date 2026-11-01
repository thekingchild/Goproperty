<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves a database backup can be restored (PLATFORM_CONSOLE.md § 4): download
 * it, decrypt it, load it into the verify database, check it has every
 * table the site has and its migration history, then empty the verify
 * database again so no copy of the data is left there. Marks the backup
 * run (and its copies at other destinations) as verified.
 */
final readonly class BackupVerifier
{
    /** Tables every backup since the first release has. */
    private const array CORE_TABLES = ['migrations', 'users', 'organizations', 'platform_staff', 'activity_log', 'backup_destinations', 'backup_runs'];

    public function __construct(
        private Restorer $restorer,
        private Config $config,
        private DatabaseManager $db,
    ) {}

    /** The newest successful database backup, as the monthly check does. */
    public function newest(): ?BackupRun
    {
        return BackupRun::query()->where('type', 'db')->where('status', 'success')->whereNotNull('backup_destination_id')->whereNotNull('filename')
            ->latest('finished_at')->latest('id')->first();
    }

    /** @return array{ok: bool, message: string} */
    public function verify(?BackupRun $run = null): array
    {
        $target = $this->config->get('backup.verify_database');

        if (! is_string($target) || $target === '') {
            return ['ok' => false, 'message' => 'No verify database: create an empty database and set BACKUP_VERIFY_DB_DATABASE.'];
        }

        $run ??= $this->newest();
        $destination = $run?->destination;

        if ($run === null || $destination === null || $run->filename === null) {
            return ['ok' => false, 'message' => $run === null ? 'There is no successful database backup to verify yet.' : 'That backup\'s destination or file is no longer known.'];
        }

        if ($run->type !== 'db' || $run->status !== 'success') {
            return ['ok' => false, 'message' => 'Only a successful database backup can be verified.'];
        }

        $work = storage_path('app/backup-temp/verify-'.Str::random(8));
        @mkdir($work, 0700, true);

        try {
            $download = $this->restorer->download($destination, $run->filename, $work);
            $sql = $this->restorer->extractDump($download['path'], $work);
            $statements = $this->restorer->import($sql, $target);

            $restored = $this->restorer->tables($target);
            $live = array_map(fn (array $table): string => (string) $table['name'], $this->db->connection()->getSchemaBuilder()->getTables($this->db->connection()->getDatabaseName()));
            $missing = array_values(array_diff($live, $restored));
            $migrations = in_array('migrations', $restored, true) ? $this->migrationCount($target) : 0;
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'The backup could not be restored: '.$exception->getMessage()];
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($work);
            $this->emptyQuietly($target);
        }

        if ($migrations === 0) {
            return ['ok' => false, 'message' => 'The restored backup is incomplete: no migration history.'];
        }

        // A backup made before the newest migrations cannot have their
        // tables: it is still a good backup of its day if the core tables are there.
        $behind = max(0, $this->db->connection()->table('migrations')->count() - $migrations);
        $required = $behind === 0 ? $missing : array_values(array_intersect($missing, self::CORE_TABLES));

        if ($required !== []) {
            return ['ok' => false, 'message' => 'The restored backup is incomplete: missing '.implode(', ', $required).'.'];
        }

        BackupRun::query()->where('type', 'db')->where('filename', $run->filename)->update(['verified_at' => now()]);

        return ['ok' => true, 'message' => "{$run->filename} from {$destination->name} restored into \"{$target}\": {$statements} statements, ".count($restored)." tables, {$migrations} migrations"
            .($behind > 0 ? " ({$behind} older than the site's)" : '').'; the verify database was emptied again.'];
    }

    private function migrationCount(string $database): int
    {
        $this->config->set('database.connections.'.Restorer::CONNECTION.'.database', $database);
        $count = $this->db->connection(Restorer::CONNECTION)->table('migrations')->count();
        $this->db->purge(Restorer::CONNECTION);

        return $count;
    }

    private function emptyQuietly(string $database): void
    {
        try {
            $this->restorer->wipe($database);
        } catch (Throwable) {
            // The verify database is wiped again before the next verify.
        }
    }
}
