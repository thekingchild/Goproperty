<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\OperationKind;
use App\Backup\Operations\OperationStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Backups for the operations dashboard and its alerts (PLATFORM_CONSOLE.md
 * § 2 "Backups", § 4): the latest result per type and destination, the last
 * verify and drill, and the failures not yet followed by a success.
 * Read-only over `backup_runs`, `backup_destinations`, `backup_operations`.
 */
final class BackupHealth
{
    public const array TYPES = ['db' => 'Database', 'files' => 'Files'];

    /** The last successful backup of a type, at any destination. */
    public function lastSuccess(string $type): ?BackupRun
    {
        return BackupRun::query()->where('type', $type)->where('status', 'success')->latest('finished_at')->latest('id')->first();
    }

    /**
     * Per active destination and the types it takes, its latest finished run.
     *
     * @return list<array{destination: BackupDestination, type: string, run: BackupRun|null}>
     */
    public function byDestination(): array
    {
        $rows = [];

        foreach (BackupDestination::query()->where('active', true)->orderBy('name')->get() as $destination) {
            foreach (array_keys(self::TYPES) as $type) {
                if (! in_array($destination->applies_to, [$type, 'both'], true)) {
                    continue;
                }

                $rows[] = ['destination' => $destination, 'type' => $type, 'run' => $this->latestFinished($type, $destination->id)];
            }
        }

        return $rows;
    }

    /**
     * Latest results that are failures with no later success: per type and
     * active destination, a run with no destination at all (none set up),
     * and the monthly verify.
     *
     * @return list<BackupFailure>
     */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->byDestination() as $row) {
            if ($row['run']?->status === 'failed') {
                $failures[] = new BackupFailure($row['type'].':'.$row['destination']->id, self::TYPES[$row['type']].' backup to '.$row['destination']->name, self::time($row['run']->finished_at));
            }
        }

        foreach (array_keys(self::TYPES) as $type) {
            $orphan = $this->latestFinished($type, null);
            $success = $this->lastSuccess($type);

            if ($orphan?->status === 'failed' && ($success === null || $success->id < $orphan->id)) {
                $failures[] = new BackupFailure($type.':none', self::TYPES[$type].' backup (no destination took it)', self::time($orphan->finished_at));
            }
        }

        $verify = $this->lastVerify();

        if ($verify?->status === OperationStatus::Failed) {
            $failures[] = new BackupFailure('verify', 'Backup verify', self::time($verify->finished_at));
        }

        return $failures;
    }

    public function lastVerify(): ?BackupOperation
    {
        return BackupOperation::query()->where('kind', OperationKind::Verify)
            ->whereIn('status', [OperationStatus::Succeeded, OperationStatus::Failed])
            ->latest('finished_at')->latest('id')->first();
    }

    /** The latest drill recorded (passed or failed), by its date. */
    public function lastDrill(): ?BackupOperation
    {
        return BackupOperation::query()->where('kind', OperationKind::Drill)->latest('drill_date')->latest('id')->first();
    }

    /** Whether a drill dated between the two Lagos days (both included) was recorded. */
    public function drillBetween(string $from, string $to): bool
    {
        return BackupOperation::query()->where('kind', OperationKind::Drill)->whereBetween('drill_date', [$from, $to])->exists();
    }

    private function latestFinished(string $type, ?int $destinationId): ?BackupRun
    {
        return BackupRun::query()->where('type', $type)
            ->when($destinationId === null, fn ($query) => $query->whereNull('backup_destination_id'), fn ($query) => $query->where('backup_destination_id', $destinationId))
            ->whereIn('status', ['success', 'failed'])
            ->latest('id')
            ->first();
    }

    private static function time(?CarbonInterface $time): ?CarbonImmutable
    {
        return $time === null ? null : CarbonImmutable::instance($time);
    }
}
