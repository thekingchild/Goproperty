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

    /**
     * Signing up an organization: the account's email must be confirmed
     * first. A phone-only account (a tenant's, P03-01b) has none, so it
     * cannot (ONBOARDING_COMPLIANCE.md § 2: the account step needs an email).
     */
    public function create(User $user): bool
    {
        return $user->email !== null && $user->hasVerifiedEmail();
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

    /**
     * Settings → Profile and Branding (BRANDING_DOCUMENTS.md § 1:
     * org-owner / org-admin). Kept while suspended, so the pages still open
     * read-only; saving needs updateProfile / updateBranding.
     */
    public function viewSettings(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization) && $this->holds($user, $organization, OrganizationPermission::ViewSettings);
    }

    /** Name, web address, address, state, LGA, CAC number. */
    public function updateProfile(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization) && $this->holds($user, $organization, OrganizationPermission::UpdateOrganization);
    }

    /** Logo, colours, letterhead, signature, prefixes, notes, layout. */
    public function updateBranding(User $user, Organization $organization): bool
    {
        return $this->updateProfile($user, $organization);
    }

    /**
     * Settings → Payment collection: bank accounts, later the Paystack
     * connection (ONBOARDING_COMPLIANCE.md § 4). Org-owner only, and never
     * while GoProperty staff impersonate the user (the permission is blocked).
     */
    public function viewPaymentSettings(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization) && $this->holds($user, $organization, OrganizationPermission::ViewPaymentSettings);
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
