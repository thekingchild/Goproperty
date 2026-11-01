<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use App\Backup\BackupVerifier;
use App\Backup\Restorer;
use App\Models\PlatformStaff;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the backups console's queued operations (P06-10) from the
 * scheduler's minute tick, in-process like the nightly backups: no exec,
 * and no queue job that a 90-second retry could start twice. One tick runs
 * what is queued, oldest first, a production restore last of all. An
 * operation still "running" after six hours died with its process and is
 * marked failed.
 */
final readonly class OperationRunner
{
    public const int STALE_HOURS = 6;

    /** At most this many operations per tick, so one tick cannot run forever. */
    private const int PER_TICK = 10;

    public function __construct(
        private BackupRunner $runner,
        private BackupVerifier $verifier,
        private Restorer $restorer,
        private ProductionRestore $production,
        private BackupAudit $audit,
    ) {}

    /** @return int operations run */
    public function runDue(): int
    {
        $this->failStale();
        $ran = 0;

        while ($ran < self::PER_TICK && ($operation = $this->claimNext()) !== null) {
            $this->run($operation);
            $ran++;
        }

        return $ran;
    }

    /**
     * The monthly check (routes/console.php): verifies the newest database
     * backup as an operation, so it shows in the console's list.
     *
     * @return array{ok: bool, message: string}
     */
    public function monthlyVerify(): array
    {
        $run = $this->verifier->newest();
        $operation = new BackupOperation;
        $operation->forceFill([
            'kind' => OperationKind::Verify,
            'status' => OperationStatus::Running,
            'backup_run_id' => $run?->id,
            'target_database' => config('backup.verify_database'),
            'started_at' => now(),
            'reason' => 'The monthly check.',
        ])->save();

        $result = $this->verifier->verify($run);
        $this->finish($operation, $result['ok'], $result['message']);

        return $result;
    }

    private function claimNext(): ?BackupOperation
    {
        $next = BackupOperation::query()
            ->where('status', OperationStatus::Queued)
            ->orderByRaw('CASE WHEN kind = ? THEN 1 ELSE 0 END', [OperationKind::RestoreProduction->value])
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return null;
        }

        // Another tick may have taken it between the read and now.
        $claimed = BackupOperation::query()->whereKey($next->id)->where('status', OperationStatus::Queued)
            ->update(['status' => OperationStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

        return $claimed === 1 ? $next->fresh() : $this->claimNext();
    }

    private function run(BackupOperation $operation): void
    {
        try {
            match ($operation->kind) {
                OperationKind::Backup => $this->backup($operation),
                OperationKind::Verify => $this->verify($operation),
                OperationKind::RestoreStaging => $this->restoreStaging($operation),
                OperationKind::RestoreProduction => $this->production->run($operation),
                OperationKind::Drill => $this->finish($operation, true, 'Recorded.'),
            };
        } catch (Throwable $exception) {
            Log::error("Backup operation #{$operation->id} failed.", ['kind' => $operation->kind->value, 'error' => $exception->getMessage()]);
            $this->finish($operation, false, $exception->getMessage());
        }
    }

    private function backup(BackupOperation $operation): void
    {
        $staff = $operation->requested_by_staff_id !== null ? PlatformStaff::query()->find($operation->requested_by_staff_id) : null;
        $runs = $this->runner->run((string) $operation->backup_type, 'manual', $staff);

        $lines = $runs->map(fn (BackupRun $run): string => ($run->destination->name ?? 'No destination').': '.($run->status === 'success' ? "saved {$run->filename}" : 'failed — '.($run->notes ?? 'unknown error')))->all();
        $this->finish($operation, $runs->every(fn (BackupRun $run): bool => $run->status === 'success'), implode("\n", $lines));
    }

    private function verify(BackupOperation $operation): void
    {
        $run = $operation->backupRun ?? throw new BackupRefused('The backup to verify is no longer known.');
        $result = $this->verifier->verify($run);
        $this->finish($operation, $result['ok'], $result['message']);
    }

    private function restoreStaging(BackupOperation $operation): void
    {
        $run = $operation->backupRun ?? throw new BackupRefused('The backup to restore is no longer known.');
        $destination = $run->destination ?? throw new BackupRefused('The destination that holds this backup has been removed.');
        $database = (string) $operation->target_database;
        $work = storage_path('app/backup-temp/staging-'.Str::random(8));
        @mkdir($work, 0700, true);

        try {
            $download = $this->restorer->download($destination, (string) $run->filename, $work);
            $sql = $this->restorer->extractDump($download['path'], $work);
            $statements = $this->restorer->import($sql, $database);
            $tables = count($this->restorer->tables($database));
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($work);
        }

        $this->finish($operation, true, "{$run->filename} from {$destination->name} restored into \"{$database}\": {$statements} statements, {$tables} tables. Run the staging site's migrations if its code is newer than the backup.");
    }

    private function finish(BackupOperation $operation, bool $ok, string $result): void
    {
        $operation->forceFill([
            'status' => $ok ? OperationStatus::Succeeded : OperationStatus::Failed,
            'finished_at' => now(),
            'result' => mb_substr($result, 0, 5000),
        ])->save();

        $event = 'backup.'.$operation->kind->value.($ok ? '_succeeded' : '_failed');
        $this->audit->record($event, $operation, $operation->requestedBy, "{$operation->kind->label()} ".($ok ? 'succeeded' : 'failed'), [
            'backup_run_id' => $operation->backup_run_id,
            'result' => mb_substr($result, 0, 1000),
        ]);

        if (! $ok) {
            Log::critical("Backup operation #{$operation->id} ({$operation->kind->label()}) failed: ".mb_substr($result, 0, 500));
        }
    }

    private function failStale(): void
    {
        BackupOperation::query()
            ->where('status', OperationStatus::Running)
            ->where('started_at', '<', now()->subHours(self::STALE_HOURS))
            ->get()
            ->each(fn (BackupOperation $operation) => $this->finish($operation, false, 'It did not finish within '.self::STALE_HOURS.' hours: the process running it stopped.'));
    }
}
