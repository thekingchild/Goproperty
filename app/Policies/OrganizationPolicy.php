<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Who may open an organization's workspace (ARCHITECTURE.md § 4–5): a member
 * whose roles there grant `workspace.access` (owners, administrators,
 * agents). Tenants and property owners use their portals, never /o/{slug}.
 * What a member may do inside comes from the rest of their permissions
 * there. Callers answer a refusal with 404, never 403, so organizations
 * cannot be discovered (ONBOARDING_COMPLIANCE.md § 1).
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
}
