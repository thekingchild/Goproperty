<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use App\Enums\Concerns\HasValues;

/**
 * awaiting_approval (production restores only) → queued → running →
 * succeeded | failed; cancelled when a queued operation is withdrawn or its
 * approval is rejected or expires. A drill is recorded as succeeded or failed.
 */
enum OperationStatus: string
{
    use HasValues;

    case AwaitingApproval = 'awaiting_approval';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingApproval => 'Waiting for approval',
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingApproval, self::Queued => 'warning',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** Not finished yet. */
    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingApproval, self::Queued, self::Running], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::AwaitingApproval->value, self::Queued->value, self::Running->value];
    }
}
