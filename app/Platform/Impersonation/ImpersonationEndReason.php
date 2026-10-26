<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Enums\Concerns\HasValues;

/**
 * How a support session ended (`impersonation_sessions.ended_reason`):
 * the staff member ended it (or started another), the 30-minute limit (or
 * the unused 60-second hand-off) ran out, a super-admin ended it from the
 * console, or access went away mid-session (the staff member lost the
 * permission or was deactivated, or the user left the organization).
 */
enum ImpersonationEndReason: string
{
    use HasValues;

    case Manual = 'manual';
    case Timeout = 'timeout';
    case EndedByStaff = 'ended_by_staff';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ended by the staff member',
            self::Timeout => 'Timed out',
            self::EndedByStaff => 'Ended by a super-admin',
            self::Revoked => 'Access ended',
        };
    }
}
