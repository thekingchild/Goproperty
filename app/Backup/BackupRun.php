<?php

declare(strict_types=1);

namespace App\Backup;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One backup job's result at one destination (DATABASE_SCHEMA.md § 8).
 *
 * @property int $id
 * @property string $type db|files
 * @property string|null $filename path inside the destination
 * @property int|null $size_bytes
 * @property int|null $backup_destination_id
 * @property string $status running|success|failed
 * @property Carbon|null $verified_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string $triggered_by schedule|manual
 * @property int|null $triggered_by_staff_id
 * @property string|null $notes
 */
#[Fillable(['type', 'filename', 'size_bytes', 'backup_destination_id', 'status', 'verified_at', 'started_at', 'finished_at', 'triggered_by', 'triggered_by_staff_id', 'notes'])]
class BackupRun extends Model
{
    /** @return BelongsTo<BackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
