<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * An invitation's state (`invitations.status`, DATABASE_SCHEMA.md § 1).
 * Only `pending` invitations can be used, and only until `expires_at`; a
 * pending one past its date is recorded as `expired` when its link is
 * opened. `declined` is for org-to-org invitations (P02-04).
 */
enum InvitationStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Cancelled => 'Revoked',
        };
    }
}
