<?php

declare(strict_types=1);

namespace Modules\Properties\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Tenancy\OrganizationContext;
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
 * refused (ImpersonationGuard). P02-03 narrows agents to their assigned
 * buildings here.
 */
final class UnitPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewProperties);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $this->belongsHere($unit) && $this->viewAny($user);
    }

    /** Add units to the building (one, or several at once). */
    public function create(User $user, Property $property): bool
    {
        return $this->buildingOpen($property) && ! $this->restricted()
            && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio);
    }

    /** Edit a unit's details and its manual status. */
    public function update(User $user, Unit $unit): bool
    {
        return $this->belongsHere($unit) && ! $unit->trashed() && $this->buildingOpen($unit->property)
            && $this->holds($user, OrganizationPermission::ManageProperties);
    }

    /** Archive (a soft delete: the unit stops counting towards the bill). */
    public function delete(User $user, Unit $unit): bool
    {
        return $this->belongsHere($unit) && ! $unit->trashed() && $this->buildingOpen($unit->property)
            && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio);
    }

    /** Bring an archived unit back. */
    public function restore(User $user, Unit $unit): bool
    {
        return $this->belongsHere($unit) && $unit->trashed() && $this->buildingOpen($unit->property) && ! $this->restricted()
            && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio);
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
