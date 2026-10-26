<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves the newest database backup can be restored (PLATFORM_CONSOLE.md
 * § 4): download it, decrypt it, load it into the verify database, and
 * check it has every table the site has and its migration history. Marks
 * the backup run as verified.
 */
final readonly class BackupVerifier
{
    public function __construct(
        private Restorer $restorer,
        private Config $config,
        private DatabaseManager $db,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function verify(): array
    {
        $target = $this->config->get('backup.verify_database');

        if (! is_string($target) || $target === '') {
            return ['ok' => false, 'message' => 'No verify database: create an empty database and set BACKUP_VERIFY_DB_DATABASE.'];
        }

        $run = BackupRun::query()->where('type', 'db')->where('status', 'success')->whereNotNull('backup_destination_id')->latest('finished_at')->latest('id')->first();
        $destination = $run?->destination;

        if ($run === null || $destination === null) {
            return ['ok' => false, 'message' => 'There is no successful database backup to verify yet.'];
        }

        $work = storage_path('app/backup-temp/verify-'.Str::random(8));
        @mkdir($work, 0700, true);

        try {
            $download = $this->restorer->downloadNewest($destination, $work);
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
        }

        if ($missing !== [] || $migrations === 0) {
            return ['ok' => false, 'message' => 'The restored backup is incomplete'.($missing !== [] ? ': missing '.implode(', ', $missing) : ': no migration history').'.'];
        }

        BackupRun::query()->where('type', 'db')->where('filename', $download['name'])->update(['verified_at' => now()]);

        return ['ok' => true, 'message' => "{$download['name']} from {$destination->name} restored into \"{$target}\": {$statements} statements, ".count($restored)." tables, {$migrations} migrations."];
    }

    private function migrationCount(string $database): int
    {
        $this->config->set('database.connections.'.Restorer::CONNECTION.'.database', $database);
        $count = $this->db->connection(Restorer::CONNECTION)->table('migrations')->count();
        $this->db->purge(Restorer::CONNECTION);

        return $count;
    }
}
