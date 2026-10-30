<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

/**
 * Which tenants GoProperty may contact on its own, unprompted by a member
 * (the reminder engine, P05-01; DATA_IMPORT.md § 3: an import "lets the
 * tenant know nothing until Invite tenants now"). A tenant may be
 * contacted when they are not archived and either were not imported, or
 * were invited to the portal since (any channel, any answer), or hold a
 * portal account. Answers for the current organization only; no
 * permission check. Bound in TenantsServiceProvider.
 */
interface TenantReach
{
    /**
     * The ids among these that may be contacted, ascending; unknown ids are left out.
     *
     * @param  list<int>  $tenantIds
     * @return list<int>
     */
    public function contactable(array $tenantIds): array;
}
