<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A two-person request (PLATFORM_CONSOLE.md § 1, DATABASE_SCHEMA.md § 8).
 * Platform-level, no organization. Its status changes only through
 * App\Platform\Approvals\ApprovalService.
 *
 * @property int $id
 * @property ApprovalAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $payload
 * @property string $summary
 * @property string|null $reason
 * @property int $requested_by_staff_id
 * @property int|null $approved_by_staff_id
 * @property int|null $rejected_by_staff_id
 * @property ApprovalStatus $status
 * @property string|null $decision_note
 * @property Carbon|null $expires_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlatformStaff $requestedBy
 * @property-read PlatformStaff|null $approvedBy
 * @property-read PlatformStaff|null $rejectedBy
 */
class ApprovalRequest extends Model
{
    protected $guarded = ['*'];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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

    /** @return BelongsTo<PlatformStaff, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'rejected_by_staff_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
