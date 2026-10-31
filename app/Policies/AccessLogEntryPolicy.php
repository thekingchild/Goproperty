<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Audit\AccessLogEntry;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Audit → Access log (PLATFORM_CONSOLE.md § 8, P06-07c):
 * `access_log.view` (super-admin and technical-admin by default).
 * Read-only: rows are written by App\Audit\AccessLog only.
 */
final class AccessLogEntryPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::AccessLogView);
    }

    public function view(PlatformStaff $actor, AccessLogEntry $entry): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, AccessLogEntry $entry): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, AccessLogEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
