<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Enums\Concerns\HasValues;

/**
 * `data_requests.status`. Exports: queued → running → ready (the file can
 * be downloaded until it expires) or failed. Erasures: awaiting_approval
 * (a second staff member with `data.purge` decides, PLATFORM_CONSOLE.md
 * § 1) → waiting (approved; runs once the retention window has passed,
 * `due_at`) → running → completed; rejected or cancelled (approval refused
 * or expired, or the organization or tenant no longer qualifies), failed.
 */
enum DataRequestStatus: string
{
    use HasValues;

    case AwaitingApproval = 'awaiting_approval';
    case Waiting = 'waiting';
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingApproval => 'Waiting for approval',
            self::Waiting => 'Approved: waiting for the retention period',
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Ready => 'Ready to download',
            self::Completed => 'Completed',
            self::Rejected => 'Not approved',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ready, self::Completed => 'success',
            self::Failed => 'danger',
            self::AwaitingApproval, self::Waiting => 'warning',
            self::Queued, self::Running => 'info',
            self::Rejected, self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingApproval, self::Waiting, self::Queued, self::Running], true);
    }
}
