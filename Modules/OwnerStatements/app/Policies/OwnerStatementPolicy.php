<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\OwnerProfileDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Who sees and marks owners' statements in the agency's workspace (P08-05),
 * registered for Models\OwnerStatementSettlement; abilities take the owner
 * profile's id:
 *
 * - viewOwner / exportOwner / downloadOwner: `owner_statements.view`
 *   (owners and administrators; kept while suspended; allowed while staff
 *   impersonate). A member narrowed to some buildings (BuildingAccess)
 *   sees an owner's statements only when every building of that owner is
 *   one of theirs (a statement is the owner's whole month); otherwise, and
 *   for an owner the organization does not have, 404.
 * - settle / unsettle / changeTerms: also `owner_statements.manage`
 *   (owners and administrators; refused while suspended and always while
 *   GoProperty staff impersonate, OrganizationPermission). A property owner
 *   holds neither: the owner portal is read-only and never reaches these.
 */
final readonly class OwnerStatementPolicy
{
    public function __construct(
        private OrganizationContext $context,
        private OwnerProfileDirectory $owners,
        private BuildingAccess $access,
    ) {}

    /** The statements pages at all (e.g. the link on an owner's page). */
    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewOwnerStatements);
    }

    public function viewOwner(User $user, int $ownerProfileId): Response|bool
    {
        return $this->reachable($user, $ownerProfileId) ?? $this->viewAny($user);
    }

    /** The months list's export (figures only, no contact details). */
    public function exportOwner(User $user, int $ownerProfileId): Response|bool
    {
        return $this->viewOwner($user, $ownerProfileId);
    }

    /** A month's statement PDF. */
    public function downloadOwner(User $user, int $ownerProfileId): Response|bool
    {
        return $this->viewOwner($user, $ownerProfileId);
    }

    public function settle(User $user, int $ownerProfileId): Response|bool
    {
        return $this->reachable($user, $ownerProfileId) ?? ($this->viewAny($user) && $this->holds($user, OrganizationPermission::ManageOwnerStatements));
    }

    public function unsettle(User $user, int $ownerProfileId): Response|bool
    {
        return $this->settle($user, $ownerProfileId);
    }

    public function changeTerms(User $user, int $ownerProfileId): Response|bool
    {
        return $this->settle($user, $ownerProfileId);
    }

    /**
     * Null when the owner is this organization's and the member's narrowing
     * covers every building of the owner (the ability decides); 404
     * otherwise.
     */
    private function reachable(User $user, int $ownerProfileId): ?Response
    {
        if (! $this->context->has() || $this->owners->find($ownerProfileId) === null) {
            return Response::denyAsNotFound();
        }

        $limited = $this->access->limitedTo($user);

        if ($limited === null) {
            return null;
        }

        $buildings = array_map(static fn (PropertySummary $building): int => $building->id, $this->owners->buildingsOf($ownerProfileId));

        return array_diff($buildings, $limited) === [] ? null : Response::denyAsNotFound();
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
