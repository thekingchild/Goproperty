<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

/**
 * How the tenant portal (P03-11a, the Tenants module's /portal pages) reads
 * a tenant's own bills: never the Billing module's models or tables
 * (ARCHITECTURE.md § 8 rule 3).
 *
 * Every method takes the tenant profile ids the portal proved are the
 * signed-in user's in the current organization (Modules\Tenants\Portal,
 * the "linked to my user" path of ARCHITECTURE.md § 4) and answers only
 * for bills issued to them: any other bill id — another tenant's, or
 * another organization's, which the organization scope hides anyway — is
 * simply not found (null), so ID guessing gets the same answer as a
 * missing bill. Answers for the current organization only
 * (OrganizationContext); bills removed by an import rollback are not
 * found. No permission check: the caller checks its policy. Bound in
 * BillingServiceProvider.
 */
interface TenantBills
{
    /**
     * The bills issued to these tenant profiles, void ones included, latest
     * due date first.
     *
     * @param  list<int>  $tenantProfileIds
     * @return list<TenantBill>
     */
    public function forTenants(array $tenantProfileIds): array;

    /**
     * One of their bills, or null.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function find(int $billId, array $tenantProfileIds): ?TenantBill;

    /**
     * One of their bills with how to pay it, or null.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function payment(int $billId, array $tenantProfileIds): ?TenantBillPayment;

    /**
     * One of their bills' invoice as a web page (branding and parties as
     * issued, status and payments now; P03-05c), or null.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function invoicePage(int $billId, array $tenantProfileIds): ?string;
}
