<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Audit\AuditLogEntry;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Audit → Audit log and a staff member's Actions
 * (PLATFORM_CONSOLE.md § 7, P06-07c): `audit.view` (super-admin by
 * default). The audit log is append-only: nobody creates, edits or deletes
 * an entry through the console.
 */
final class AuditLogEntryPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::AuditView);
    }

    public function view(PlatformStaff $actor, AuditLogEntry $entry): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, AuditLogEntry $entry): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, AuditLogEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
