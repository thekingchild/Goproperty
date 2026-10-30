<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The organization's WhatsApp settings (ARCHITECTURE.md § 6.7), in the
 * current organization:
 *
 * - viewAny: the settings pages (`settings.view`: owners and
 *   administrators; kept while suspended, so they can still look);
 * - connect: the credentials (IDs, access token, app secret), the
 *   webhook's verify token, disconnecting — `whatsapp.connection`,
 *   org-owners only, never while staff impersonate (a credential change);
 * - manageMessages: syncing templates, the event → template mapping and
 *   channel order — `whatsapp.messages`, owners and administrators.
 *
 * - recordOptIn: recording a tenant's consent given (or taken back) in
 *   person — `tenants.portal_access`, like confirming their number in
 *   person (owners, administrators, agents; never while staff impersonate);
 *   the caller also checks the member may see that tenant (TenantDirectory).
 *
 * In a suspended organization the managing permissions are dropped
 * (OrganizationWriteGuard), so the pages are read-only.
 */
final class WhatsAppPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewSettings);
    }

    #[BlockedWhileImpersonating]
    public function connect(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageWhatsAppConnection);
    }

    public function manageMessages(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageWhatsAppMessages);
    }

    #[BlockedWhileImpersonating]
    public function recordOptIn(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageTenantPortalAccess);
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
