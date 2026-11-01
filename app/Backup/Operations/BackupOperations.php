<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Console\StepUp;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The backups console's requests (P06-10; PLATFORM_CONSOLE.md § 4): run a
 * backup now, verify one, restore one to staging, ask for a restore to
 * production (two-person rule), withdraw a queued request, record a restore
 * drill. Each check is made here, not only in the screens. Requests wait
 * for the scheduler's minute tick (OperationRunner), which runs them
 * in-process like the nightly backups. Every request is audited (log `backups`).
 */
final readonly class BackupOperations
{
    public const int REASON_MIN = 10;

    public const int REASON_MAX = 500;

    public function __construct(
        private ApprovalService $approvals,
        private BackupAudit $audit,
        private StepUp $stepUp,
        private Config $config,
    ) {}

    public function queueBackup(PlatformStaff $staff, string $type): BackupOperation
    {
        $this->authorize($staff, StaffPermission::BackupsRun);

        if (! in_array($type, BackupRunner::TYPES, true)) {
            throw new BackupRefused('The backup is of the database or of the uploaded files.');
        }

        return DB::transaction(function () use ($staff, $type): BackupOperation {
            if ($this->open(OperationKind::Backup)->where('backup_type', $type)->exists()) {
                throw new BackupRefused(($type === 'db' ? 'A database' : 'A files').' backup is already queued or running.');
            }

            $operation = $this->create(OperationKind::Backup, $staff, ['backup_type' => $type]);
            $this->audit->record('backup.run_requested', $operation, $staff, ($type === 'db' ? 'Database' : 'Files').' backup requested', ['type' => $type]);

            return $operation;
        });
    }

    public function queueVerify(PlatformStaff $staff, BackupRun $run): BackupOperation
    {
        $this->authorize($staff, StaffPermission::BackupsVerify);
        $this->usableDatabaseBackup($run);

        if (! is_string($this->config->get('backup.verify_database')) || $this->config->get('backup.verify_database') === '') {
            throw new BackupRefused('There is no verify database: create an empty one in the control panel and set BACKUP_VERIFY_DB_DATABASE.');
        }

        return DB::transaction(function () use ($staff, $run): BackupOperation {
            if ($this->open(OperationKind::Verify)->exists()) {
                throw new BackupRefused('A verify is already queued or running.');
            }

            $operation = $this->create(OperationKind::Verify, $staff, ['backup_run_id' => $run->id, 'target_database' => (string) $this->config->get('backup.verify_database')]);
            $this->audit->record('backup.verify_requested', $operation, $staff, "Verify of {$run->filename} requested", ['backup_run_id' => $run->id, 'filename' => $run->filename]);

            return $operation;
        });
    }

    public function queueStagingRestore(PlatformStaff $staff, BackupRun $run, string $reason): BackupOperation
    {
        $this->authorize($staff, StaffPermission::BackupsRestoreStaging);
        $this->usableDatabaseBackup($run);
        $reason = $this->reason($reason);
        $target = $this->stagingDatabase();

        return DB::transaction(function () use ($staff, $run, $reason, $target): BackupOperation {
            if ($this->open(OperationKind::RestoreStaging)->exists()) {
                throw new BackupRefused('A restore to staging is already queued or running.');
            }

            $operation = $this->create(OperationKind::RestoreStaging, $staff, ['backup_run_id' => $run->id, 'target_database' => $target, 'reason' => $reason]);
            $this->audit->record('backup.staging_restore_requested', $operation, $staff, "Restore of {$run->filename} to staging requested", [
                'backup_run_id' => $run->id,
                'filename' => $run->filename,
                'database' => $target,
            ], $reason);

            return $operation;
        });
    }

    /** The words a staff member types to confirm a production restore: the backup's name. */
    public function confirmationPhrase(BackupRun $run): string
    {
        return basename((string) $run->filename, '.zip');
    }

    /**
     * Asks for a restore to production: the staff member has confirmed with
     * their password and 2FA code (StepUp) and typed the backup's name; a
     * second staff member with `backups.approve_production_restore` decides
     * the approval request in Staff → Approvals (ProductionRestoreApproval).
     */
    public function requestProductionRestore(PlatformStaff $staff, BackupRun $run, string $typed, string $reason): BackupOperation
    {
        $this->authorize($staff, StaffPermission::BackupsRestoreProduction);
        $this->usableDatabaseBackup($run);
        $reason = $this->reason($reason);

        if (! $this->stepUp->isFresh()) {
            throw new BackupRefused('Confirm with your password and authenticator code first.');
        }

        if (trim($typed) !== $this->confirmationPhrase($run)) {
            throw new BackupRefused('Type the backup\'s name exactly to confirm: '.$this->confirmationPhrase($run).'.');
        }

        return DB::transaction(function () use ($staff, $run, $reason): BackupOperation {
            if ($this->open(OperationKind::RestoreProduction)->lockForUpdate()->exists()) {
                throw new BackupRefused('A restore to production is already waiting for approval, queued or running.');
            }

            $operation = $this->create(OperationKind::RestoreProduction, $staff, [
                'backup_run_id' => $run->id,
                'target_database' => (string) DB::connection()->getDatabaseName(),
                'reason' => $reason,
                'status' => OperationStatus::AwaitingApproval,
            ]);

            $finished = $run->finished_at?->timezone('Africa/Lagos')->format('j M Y H:i') ?? 'unknown time';
            $request = $this->approvals->request(
                ApprovalAction::ProductionRestore,
                $staff,
                $operation,
                "Restore production from {$run->filename} ({$finished}, {$run->destination?->name})",
                ['backup_operation_id' => $operation->id, 'backup_run_id' => $run->id, 'filename' => $run->filename],
                $reason,
            );

            $operation->forceFill(['approval_request_id' => $request->id])->save();
            $this->audit->record('backup.production_restore_requested', $operation, $staff, "Restore of production from {$run->filename} requested", [
                'backup_run_id' => $run->id,
                'filename' => $run->filename,
                'approval_request_id' => $request->id,
            ], $reason);

            return $operation;
        });
    }

    /** Withdraws a queued operation before the scheduler starts it. */
    public function cancel(PlatformStaff $staff, BackupOperation $operation): BackupOperation
    {
        $this->authorize($staff, self::permissionFor($operation->kind));

        return DB::transaction(function () use ($staff, $operation): BackupOperation {
            $locked = BackupOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OperationStatus::Queued) {
                throw new BackupRefused($locked->status === OperationStatus::AwaitingApproval
                    ? 'It is waiting for approval: the approver rejects it in Staff → Approvals, or it expires.'
                    : 'Only a queued operation can be withdrawn.');
            }

            $locked->forceFill(['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'result' => "Withdrawn by {$staff->name}."])->save();
            $this->audit->record('backup.operation_cancelled', $locked, $staff, "{$locked->kind->label()} withdrawn", ['kind' => $locked->kind->value]);

            $operation->setRawAttributes($locked->getAttributes(), true);

            return $operation;
        });
    }

    /** Records a restore drill done by hand (the quarterly drill, PLATFORM_CONSOLE.md § 4). */
    public function recordDrill(PlatformStaff $staff, string $date, bool $passed, string $notes): BackupOperation
    {
        $this->authorize($staff, StaffPermission::BackupsRestoreStaging);
        $notes = $this->reason($notes);
        $day = CarbonImmutable::parse($date, 'Africa/Lagos')->startOfDay();
        $today = CarbonImmutable::now('Africa/Lagos')->startOfDay();

        if ($day->greaterThan($today) || $day->lessThan($today->subYear())) {
            throw new BackupRefused('The drill\'s date is today or within the last year.');
        }

        $operation = $this->create(OperationKind::Drill, $staff, [
            'status' => $passed ? OperationStatus::Succeeded : OperationStatus::Failed,
            'drill_date' => $day->toDateString(),
            'reason' => $notes,
            'result' => $passed ? 'The drill passed.' : 'The drill failed.',
            'finished_at' => now(),
        ]);
        $this->audit->record('backup.drill_recorded', $operation, $staff, 'Restore drill of '.$day->format('j M Y').' recorded: '.($passed ? 'passed' : 'failed'), ['passed' => $passed, 'date' => $day->toDateString()]);

        return $operation;
    }

    /**
     * Approving the request queues the restore (inside the approval's
     * transaction); called by ProductionRestoreApproval.
     */
    public function approveProduction(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $operation = $this->operationOf($request);

        if ($operation === null || $operation->status !== OperationStatus::AwaitingApproval) {
            throw new ApprovalRefused('This restore is no longer waiting for approval.');
        }

        if ($this->open(OperationKind::RestoreProduction)->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Running->value])->exists()) {
            throw new ApprovalRefused('Another restore to production is queued or running.');
        }

        $operation->forceFill(['status' => OperationStatus::Queued, 'approved_by_staff_id' => $approver->id])->save();
        $this->audit->record('backup.production_restore_approved', $operation, $approver, 'Restore of production approved', [
            'approval_request_id' => $request->id,
            'requested_by_staff_id' => $operation->requested_by_staff_id,
        ]);
    }

    /** A rejected or expired request: the restore never runs. */
    public function productionNotApproved(ApprovalRequest $request): void
    {
        $operation = $this->operationOf($request);

        if ($operation === null || $operation->status !== OperationStatus::AwaitingApproval) {
            return;
        }

        $outcome = $request->status === ApprovalStatus::Expired ? 'expired without a decision' : 'was rejected';
        $operation->forceFill(['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'result' => "The approval {$outcome}."])->save();
    }

    public function refusalForApprover(PlatformStaff $decider, ApprovalRequest $request): ?string
    {
        $operation = $this->operationOf($request);

        return $operation === null || $operation->status !== OperationStatus::AwaitingApproval ? 'This restore is no longer waiting for approval.' : null;
    }

    public static function permissionFor(OperationKind $kind): StaffPermission
    {
        return match ($kind) {
            OperationKind::Backup => StaffPermission::BackupsRun,
            OperationKind::Verify => StaffPermission::BackupsVerify,
            OperationKind::RestoreStaging, OperationKind::Drill => StaffPermission::BackupsRestoreStaging,
            OperationKind::RestoreProduction => StaffPermission::BackupsRestoreProduction,
        };
    }

    /** The staging database a restore to staging replaces, or the reason there is none. */
    public function stagingDatabase(): string
    {
        $target = $this->config->get('backup.staging_database');
        $live = (string) DB::connection()->getDatabaseName();

        if (! is_string($target) || $target === '') {
            throw new BackupRefused('There is no staging database: set BACKUP_STAGING_DB_DATABASE to the database the staging site uses.');
        }

        if ($target === $live) {
            throw new BackupRefused('BACKUP_STAGING_DB_DATABASE names this site\'s own database: it must be the staging site\'s.');
        }

        return $target;
    }

    /** @return Builder<BackupOperation> */
    private function open(OperationKind $kind): Builder
    {
        return BackupOperation::query()->where('kind', $kind)->whereIn('status', OperationStatus::openValues());
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(OperationKind $kind, PlatformStaff $staff, array $attributes): BackupOperation
    {
        $operation = new BackupOperation;
        $operation->forceFill($attributes + [
            'kind' => $kind,
            'status' => OperationStatus::Queued,
            'requested_by_staff_id' => $staff->id,
        ])->save();

        return $operation;
    }

    private function authorize(PlatformStaff $staff, StaffPermission $permission): void
    {
        if (! $staff->holdsStaffPermission($permission)) {
            throw new BackupRefused('You do not have the permission for this.');
        }
    }

    private function usableDatabaseBackup(BackupRun $run): void
    {
        if ($run->type !== 'db' || $run->status !== 'success' || $run->filename === null) {
            throw new BackupRefused('Only a successful database backup can be verified or restored.');
        }

        if ($run->destination === null) {
            throw new BackupRefused('The destination that holds this backup has been removed.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new BackupRefused('Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters.');
        }

        return $reason;
    }

    private function operationOf(ApprovalRequest $request): ?BackupOperation
    {
        $id = $request->payload['backup_operation_id'] ?? null;

        return is_numeric($id) ? BackupOperation::query()->whereKey((int) $id)->lockForUpdate()->first() : null;
    }
}
