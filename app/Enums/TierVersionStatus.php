<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a version of a Transaction Tier's terms stands (P06-08,
 * `transaction_tiers.status`): waiting for a second staff member, approved
 * (in force from its date), not approved (rejected or expired), or
 * cancelled before its date.
 */
enum TierVersionStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired unapproved',
            self::Cancelled => 'Cancelled',
        };
    }
}
