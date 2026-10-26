<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Enums\Concerns\HasValues;
use App\Platform\Staff\StaffPermission;

/**
 * How a support session may act (PLATFORM_CONSOLE.md § 6). Read-only
 * changes nothing at all; write mode may change business data, but never
 * the always-blocked actions (OrganizationPermission::blockedWhileImpersonating()),
 * and each write session needs a second staff member's approval.
 */
enum ImpersonationMode: string
{
    use HasValues;

    case ReadOnly = 'read_only';
    case Write = 'write';

    /** The staff permission needed to start a session in this mode. */
    public function permission(): StaffPermission
    {
        return match ($this) {
            self::ReadOnly => StaffPermission::ImpersonateReadOnly,
            self::Write => StaffPermission::ImpersonateWrite,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => 'Read-only',
            self::Write => 'Write',
        };
    }

    public function needsApproval(): bool
    {
        return $this === self::Write;
    }
}
