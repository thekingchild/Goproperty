<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OnboardingStep;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Who may open an organization's workspace (ARCHITECTURE.md § 4–5): a member
 * whose roles there grant `workspace.access` (owners, administrators,
 * agents). Tenants and property owners use their portals, never /o/{slug}.
 * What a member may do inside comes from the rest of their permissions
 * there. Callers answer a refusal with 404, never 403, so organizations
 * cannot be discovered (ONBOARDING_COMPLIANCE.md § 1).
 *
 * Signup (ONBOARDING_COMPLIANCE.md § 2): any verified user may create an
 * organization; each later wizard step needs the permission that step
 * touches (OnboardingStep::permission(), all held by `org-owner`).
 */
final class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return in_array((int) $organization->getKey(), $user->workspaceOrganizationIds(), true);
    }

    /** Making it the organization the user lands on after signing in. */
    public function switchTo(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }

    /** Signing up an organization: the account's email must be confirmed first. */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /** Seeing or saving one step of the signup wizard. */
    public function continueSignup(User $user, Organization $organization, OnboardingStep $step): bool
    {
        $permission = $step->permission();

        return $permission !== null && $this->view($user, $organization) && $this->holds($user, $organization, $permission);
    }

    /** The setup checklist on the workspace dashboard: the people who set the organization up. */
    public function viewSetupChecklist(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization) && $this->holds($user, $organization, OrganizationPermission::UpdateOrganization);
    }

    private function holds(User $user, Organization $organization, OrganizationPermission $permission): bool
    {
        return app(OrganizationContext::class)->run($organization, static function () use ($user, $permission): bool {
            try {
                return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
            } catch (PermissionDoesNotExist) {
                return false;
            }
        });
    }
}
