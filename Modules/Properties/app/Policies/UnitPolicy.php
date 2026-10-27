<?php

declare(strict_types=1);

namespace Modules\Properties\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Units in the current organization (ARCHITECTURE.md § 5, § 6.2), on the
 * same permissions as their buildings: `properties.view` (org-owner,
 * org-admin, agent) sees them; `properties.manage` (the same three) edits
 * a unit of an active building; `properties.portfolio` (org-owner,
 * org-admin) adds units, archives and restores them, because each unit
 * counts towards the GoProperty bill (BILLING_PAYMENTS.md § 1.4), as adding
 * and archiving buildings does. An archived building's units are
 * read-only. Property owners and tenants never open the workspace; another
 * organization's units never reach a policy (the organization scope hides
 * them).
 *
 * A `restricted` organization adds no units and restores none
 * (ONBOARDING_COMPLIANCE.md § 5.5). The central guards apply on top: in a
 * suspended organization only the reads pass (OrganizationWriteGuard), and
 * while GoProperty staff impersonate, archiving a unit (`delete`) is
 * refused (ImpersonationGuard).
 *
 * Agent narrowing (P02-03, ARCHITECTURE.md § 5): an agent with building
 * assignments sees and edits only the units of those buildings; any other
 * unit or building answers 404 (Response::denyAsNotFound), as another
 * organization's does.
 */
final class UnitPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AgentNarrowing $narrowing,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewProperties);
    }

    public function view(User $user, Unit $unit): Response|bool
    {
        return $this->reachable($user, $unit) ?? $this->viewAny($user);
    }

    /** Add units to the building (one, or several at once). */
    public function create(User $user, Property $property): Response|bool
    {
        if (! $this->context->has() || $property->organization_id !== $this->context->id() || $property->trashed()) {
            return false;
        }

        return $this->assigned($user, $property->id)
            ?? ($this->buildingOpen($property) && ! $this->restricted() && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio));
    }

    /** Edit a unit's details and its manual status. */
    public function update(User $user, Unit $unit): Response|bool
    {
        return $this->reachable($user, $unit)
            ?? (! $unit->trashed() && $this->buildingOpen($unit->property) && $this->holds($user, OrganizationPermission::ManageProperties));
    }

    /** Archive (a soft delete: the unit stops counting towards the bill). */
    public function delete(User $user, Unit $unit): Response|bool
    {
        return $this->reachable($user, $unit)
            ?? (! $unit->trashed() && $this->buildingOpen($unit->property) && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio));
    }

    /** Bring an archived unit back. */
    public function restore(User $user, Unit $unit): Response|bool
    {
        return $this->reachable($user, $unit)
            ?? ($unit->trashed() && $this->buildingOpen($unit->property) && ! $this->restricted()
                && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio));
    }

    /**
     * Null when the unit is this organization's and its building within the
     * member's assignments (the ability's own rule decides); false for
     * another organization's unit; 404 for a unit outside a narrowed
     * agent's buildings, whatever the ability.
     */
    private function reachable(User $user, Unit $unit): Response|false|null
    {
        return $this->belongsHere($unit) ? $this->assigned($user, $unit->property_id) : false;
    }

    private function assigned(User $user, int $propertyId): ?Response
    {
        return $this->narrowing->allows($user, $propertyId) ? null : Response::denyAsNotFound();
    }

    private function belongsHere(Unit $unit): bool
    {
        return $this->context->has() && $unit->organization_id === $this->context->id();
    }

    /** The building is this organization's, not archived and not removed by an import rollback. */
    private function buildingOpen(?Property $property): bool
    {
        return $property instanceof Property && $this->context->has() && $property->organization_id === $this->context->id()
            && ! $property->trashed() && ! $property->isArchived();
    }

    private function restricted(): bool
    {
        return $this->context->has() && $this->context->get()->status === OrganizationStatus::Restricted;
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
