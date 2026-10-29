<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * How the owner portal (the Properties module's /owner pages) reads the
 * tenants of an owner's buildings in full (P03-16; product owner
 * 2026-10-29, docs/plans/owner-visibility-2026-10-29.md item 1): never the
 * Tenants module's models or tables (ARCHITECTURE.md § 8 rule 3).
 *
 * The caller passes only tenants it proved hold or held a lease (not a
 * draft) in one of the signed-in owner's buildings in the current
 * organization (through Leasing's Contracts\OwnerLeases). Answers for the
 * current organization only (OrganizationContext), so another
 * organization's tenant is simply not found. The ID number never leaves
 * this module in full: TenantDetails carries its last four characters
 * only. Read-only, no permission check: the caller checks its policy.
 * Bound in TenantsServiceProvider.
 */
interface OwnerTenants
{
    /**
     * The tenants of these ids (archived ones too) that the current
     * organization has; unknown ids are left out.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, TenantDetails> by id
     */
    public function details(array $tenantIds): array;
}
