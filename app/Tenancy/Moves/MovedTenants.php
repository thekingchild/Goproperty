<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

/**
 * Which tenants of the current organization moved out with their
 * buildings (BILLING_PAYMENTS.md § 7.4, product owner 2026-10-28): the
 * record left behind keeps its history and shows "Moved to {organization}
 * on {date}"; it cannot be archived while it has unpaid bills or unsettled
 * credit, and archives itself once both are clear (the Tenants module).
 *
 * A tenant moved out when a handover that took them was accepted (a
 * management that is still on, or a one-way return) and this organization
 * offered it; once a management ends and the tenant comes back to this
 * record, they no longer count as moved. Implemented by the Handovers
 * module from its own handover rows (it knows where each tenant went; the
 * other organization's tenant data is never read, only its name), bound
 * from its provider; a module that needs it checks app()->bound() first.
 * Answers for the current organization only.
 */
interface MovedTenants
{
    /**
     * Where each of these tenants moved, the latest move each.
     *
     * @param  list<int>  $tenantProfileIds
     * @return array<int, MovedTenant> by tenant profile id
     */
    public function movedOut(array $tenantProfileIds): array;

    /**
     * Every tenant of the current organization that moved out.
     *
     * @return list<int>
     */
    public function movedOutIds(): array;

    /**
     * The organizations with tenants that moved out (ids only, for the
     * daily sweep; no organization context needed).
     *
     * @return list<int>
     */
    public function organizationIds(): array;
}
