<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use App\Backup\Restorer;
use App\Models\PlatformStaff;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restores an approved database backup over the site's own database
 * (P06-10; PLATFORM_CONSOLE.md § 4). In order:
 *
 * 1. the chosen backup is downloaded and decrypted (if it cannot be,
 *    nothing happens);
 * 2. maintenance mode on, so nobody writes while it runs, and a safety
 *    backup of the database as it is now (triggered_by `safety`); if that
 *    fails, nothing is restored;
 * 3. every table is replaced with the backup's contents;
 * 4. newer migrations run, the cache, cache locks, queued jobs and sessions
 *    from the backup's time are emptied (everyone signs in again), and the
 *    backup records (destinations, runs and these operations) are carried
 *    over from before the restore, so the list still shows every backup,
 *    the safety backup included;
 * 5. maintenance mode off.
 *
 * If step 3 or 4 fails, the safety backup is restored the same way and
 * the operation fails; if that fails too, the site stays in maintenance
 * mode with a critical log line, for docs/runbooks/restore.md.
 */
final readonly class ProductionRestore
{
    /** Kept across the restore: what the backups console itself shows. */
    public const array CARRIED_TABLES = ['backup_destinations', 'backup_runs', 'backup_operations'];

    /** Emptied after the restore: state from the backup's time that must not come back. */
    public const array EMPTIED_TABLES = ['cache', 'cache_locks', 'jobs', 'sessions'];

    public function __construct(
        private BackupRunner $runner,
        private Restorer $restorer,
        private BackupAudit $audit,
        private DatabaseManager $db,
        private Artisan $artisan,
    ) {}

    public function run(BackupOperation $operation): void
    {
        $run = $operation->backupRun ?? throw new BackupRefused('The backup to restore is no longer known.');
        $destination = $run->destination ?? throw new BackupRefused('The destination that holds this backup has been removed.');
        $approver = $operation->approved_by_staff_id !== null ? PlatformStaff::query()->find($operation->approved_by_staff_id) : null;

        if ($operation->approval_request_id === null || $approver === null) {
            throw new BackupRefused('A restore to production needs an approval.');
        }

        $work = storage_path('app/backup-temp/production-'.Str::random(8));
        @mkdir($work, 0700, true);

        // The backup is fetched and decrypted while the site is still up: a
        // missing or unreadable backup never takes the site down, and the
        // safety backup taken next cannot replace the file being restored.
        try {
            $download = $this->restorer->download($destination, (string) $run->filename, $work);
            $sql = $this->restorer->extractDump($download['path'], $work);
        } catch (Throwable $exception) {
            $this->clean($work);
            $this->finish($operation, false, 'Nothing was restored: '.$exception->getMessage());

            return;
        }

        $maintenance = app(MaintenanceMode::class);
        $wasDown = $maintenance->active();
        $stayDown = false;
        $maintenance->activate(['except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]);

        try {
            $safety = $this->runner->run('db', 'safety', $approver)->first(fn (BackupRun $copy): bool => $copy->status === 'success');
            // Read now: once the tables are replaced the relation cannot be loaded.
            $safetyDestination = $safety?->destination;

            if ($safety === null || $safetyDestination === null) {
                $this->finish($operation, false, 'The safety backup failed, so nothing was restored. See the backups list for why.');

                return;
            }

            $operation->forceFill(['safety_backup_filename' => $safety->filename])->save();
            $carried = $this->snapshot();

            try {
                $statements = $this->restorer->replaceLive($sql);
                $this->afterReplace($carried);
            } catch (Throwable $exception) {
                if (! $this->rollBack($safetyDestination, (string) $safety->filename, $carried, $work)) {
                    $stayDown = true;
                    Log::critical('A restore to production failed and the safety backup could not be put back: the site stays in maintenance mode. Follow docs/runbooks/restore.md.', [
                        'operation' => $operation->id,
                        'backup' => $run->filename,
                        'safety_backup' => $safety->filename,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                $this->finish($operation, false, "The restore failed, and the site was put back as it was from the safety backup {$safety->filename}: {$exception->getMessage()}");

                return;
            }

            $this->finish($operation, true, "Production was restored from {$run->filename} ({$destination->name}): {$statements} statements. The safety backup taken first is {$safety->filename}. Everyone was signed out.");
        } catch (Throwable $exception) {
            $this->finish($operation, false, 'Nothing was restored: '.$exception->getMessage());
        } finally {
            $this->clean($work);

            if (! $stayDown && ! $wasDown) {
                $maintenance->deactivate();
            }
        }
    }

    private function clean(string $work): void
    {
        foreach (glob($work.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($work);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $rows = [];

        foreach (self::CARRIED_TABLES as $table) {
            $rows[$table] = array_values($this->db->connection()->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        }

        return $rows;
    }

    /** @param  array<string, list<array<string, mixed>>>  $carried */
    private function afterReplace(array $carried): void
    {
        $this->artisan->call('migrate', ['--force' => true]);

        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        try {
            foreach (self::EMPTIED_TABLES as $table) {
                if ($schema->hasTable($table)) {
                    $connection->table($table)->delete();
                }
            }

            foreach ($carried as $table => $rows) {
                $connection->table($table)->delete();

                foreach (array_chunk($rows, 200) as $chunk) {
                    $connection->table($table)->insert($chunk);
                }
            }
        } finally {
            $schema->enableForeignKeyConstraints();
        }

        try {
            Cache::flush();
        } catch (Throwable) {
            // A database cache was emptied above; another store refills itself.
        }
    }

    /** @param  array<string, list<array<string, mixed>>>  $carried */
    private function rollBack(BackupDestination $destination, string $filename, array $carried, string $work): bool
    {
        try {
            $download = $this->restorer->download($destination, $filename, $work);
            $this->restorer->replaceLive($this->restorer->extractDump($download['path'], $work));
            $this->afterReplace($carried);

            return true;
        } catch (Throwable $exception) {
            Log::critical('Putting back the safety backup failed.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /** The operation row was carried over, so it is written after the restore too. */
    private function finish(BackupOperation $operation, bool $ok, string $result): void
    {
        $operation->forceFill([
            'status' => $ok ? OperationStatus::Succeeded : OperationStatus::Failed,
            'finished_at' => now(),
            'result' => $result,
        ]);
        $operation->exists = BackupOperation::query()->whereKey($operation->id)->exists();
        $operation->save();

        $this->audit->record($ok ? 'backup.production_restored' : 'backup.production_restore_failed', $operation, $operation->requestedBy, $ok ? 'Production restored from a backup' : 'Restore of production failed', [
            'backup_run_id' => $operation->backup_run_id,
            'approval_request_id' => $operation->approval_request_id,
            'approved_by_staff_id' => $operation->approved_by_staff_id,
            'safety_backup' => $operation->safety_backup_filename,
            'result' => mb_substr($result, 0, 1000),
        ], $operation->reason);

        if (! $ok) {
            Log::critical("The restore to production #{$operation->id} failed: {$result}");
        }
    }
}
