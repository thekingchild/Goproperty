<?php

declare(strict_types=1);

namespace App\Policies;

use App\Backup\BackupDestination;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;

/**
 * Operations → Backup destinations (P06-10; PLATFORM_CONSOLE.md § 4):
 * `backups.destinations` (technical-admin, super-admin). Never deleted:
 * switched off instead. App\Backup\Operations\Destinations re-checks.
 */
final class BackupDestinationPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::BackupDestinations);
    }

    public function view(PlatformStaff $actor, BackupDestination $destination): bool
    {
        return $this->viewAny($actor);
    }

    public function create(PlatformStaff $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(PlatformStaff $actor, BackupDestination $destination): bool
    {
        return $this->viewAny($actor);
    }

    public function delete(PlatformStaff $actor, BackupDestination $destination): bool
    {
        return false;
    }
}
