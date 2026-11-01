<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Backup\BackupRun;
use App\Models\PlatformStaff;
use App\Policies\BackupOperationPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing staff asked the backups console to do (P06-10,
 * DATABASE_SCHEMA.md § 8 `backup_operations`). Written only by
 * App\Backup\Operations\BackupOperations and the runner; never deleted.
 *
 * @property int $id
 * @property OperationKind $kind
 * @property string|null $backup_type db|files, for a backup
 * @property int|null $backup_run_id the backup verified or restored
 * @property string|null $target_database
 * @property OperationStatus $status
 * @property int|null $requested_by_staff_id null when the schedule asked
 * @property int|null $approval_request_id
 * @property int|null $approved_by_staff_id
 * @property string|null $safety_backup_filename
 * @property string|null $reason
 * @property string|null $result
 * @property Carbon|null $drill_date
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(BackupOperationPolicy::class)]
class BackupOperation extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<BackupRun, $this> */
    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'requested_by_staff_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'approved_by_staff_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => OperationKind::class,
            'status' => OperationStatus::class,
            'drill_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
