<?php

declare(strict_types=1);

namespace Modules\Import\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Modules\Import\Models\ImportBatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Imports in the current organization (DATA_IMPORT.md § 5): only org-owners
 * and org-admins. `imports.view` shows the history, reports and error
 * workbooks (also to GoProperty staff impersonating a user, and while the
 * organization is suspended); `imports.manage` uploads, maps, runs the dry
 * run, commits and rolls back. Another organization's batches never reach
 * a policy: the organization scope hides them.
 *
 * The central guards apply on top: `imports.manage` is dropped while the
 * organization is suspended and whenever staff impersonate
 * (OrganizationPermission::blockedWhileImpersonating()), and commit and
 * rollback are also marked #[BlockedWhileImpersonating] so they stay
 * refused even if that classification changes.
 */
final class ImportBatchPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewImports);
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->belongsHere($batch) && $this->viewAny($user);
    }

    /** Upload a file (and download the template to fill in). */
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageImports);
    }

    /** Change the mapping and run the dry run, before the commit. */
    public function update(User $user, ImportBatch $batch): bool
    {
        return $this->belongsHere($batch) && $batch->status->isPreparing() && $this->create($user);
    }

    #[BlockedWhileImpersonating]
    public function commit(User $user, ImportBatch $batch): bool
    {
        return $this->belongsHere($batch) && $this->create($user);
    }

    #[BlockedWhileImpersonating]
    public function rollback(User $user, ImportBatch $batch): bool
    {
        return $this->belongsHere($batch) && $batch->status->hasCommitted() && $this->create($user);
    }

    private function belongsHere(ImportBatch $batch): bool
    {
        return $this->context->has() && $batch->organization_id === $this->context->id();
    }

    private function holds(User $user, OrganizationPermission $permission): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
