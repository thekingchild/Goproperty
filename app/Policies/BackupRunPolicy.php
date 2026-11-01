<?php

declare(strict_types=1);

namespace App\Policies;

use App\Backup\BackupRun;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission as P;

/**
 * Operations → Backups (P06-10; PLATFORM_CONSOLE.md § 4): the list of
 * backups, seen by staff holding any backup permission (technical-admin,
 * super-admin). Each action needs its own permission; restores and verifies
 * take only a successful database backup whose destination is still known.
 * App\Backup\Operations\BackupOperations re-checks.
 */
final class BackupRunPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        foreach ([P::BackupsRun, P::BackupsDownload, P::BackupsVerify, P::BackupsRestoreStaging, P::BackupsRestoreProduction, P::BackupDestinations] as $permission) {
            if ($actor->holdsStaffPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(PlatformStaff $actor, BackupRun $run): bool
    {
        return $this->viewAny($actor);
    }

    public function run(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::BackupsRun);
    }

    public function download(PlatformStaff $actor, BackupRun $run): bool
    {
        return $actor->holdsStaffPermission(P::BackupsDownload) && $this->stored($run);
    }

    public function verify(PlatformStaff $actor, BackupRun $run): bool
    {
        return $actor->holdsStaffPermission(P::BackupsVerify) && $this->restorable($run);
    }

    public function restoreStaging(PlatformStaff $actor, BackupRun $run): bool
    {
        return $actor->holdsStaffPermission(P::BackupsRestoreStaging) && $this->restorable($run);
    }

    public function restoreProduction(PlatformStaff $actor, BackupRun $run): bool
    {
        return $actor->holdsStaffPermission(P::BackupsRestoreProduction) && $this->restorable($run);
    }

    public function recordDrill(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::BackupsRestoreStaging);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, BackupRun $run): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, BackupRun $run): bool
    {
        return false;
    }

    private function stored(BackupRun $run): bool
    {
        return $run->status === 'success' && $run->filename !== null && $run->backup_destination_id !== null;
    }

    private function restorable(BackupRun $run): bool
    {
        return $run->type === 'db' && $this->stored($run);
    }
}
