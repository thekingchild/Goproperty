<?php

declare(strict_types=1);

namespace App\Policies;

use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\BackupOperations;
use App\Backup\Operations\OperationStatus;
use App\Models\PlatformStaff;

/**
 * Operations → Backup activity (P06-10): what staff asked the backups
 * console to do, seen by anyone who sees the backups. A queued operation is
 * withdrawn by staff holding the permission that queues it.
 */
final class BackupOperationPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return (new BackupRunPolicy)->viewAny($actor);
    }

    public function view(PlatformStaff $actor, BackupOperation $operation): bool
    {
        return $this->viewAny($actor);
    }

    public function cancel(PlatformStaff $actor, BackupOperation $operation): bool
    {
        return $operation->status === OperationStatus::Queued && $actor->holdsStaffPermission(BackupOperations::permissionFor($operation->kind));
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, BackupOperation $operation): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, BackupOperation $operation): bool
    {
        return false;
    }
}
