<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Media\Media;

/**
 * How the tenant portal (P03-11b, the Tenants module's /portal pages) reads
 * a tenant's own receipts and opens them as printed: never the Payments
 * module's models or tables (ARCHITECTURE.md § 8 rule 3).
 *
 * Every method takes the tenant profile ids the portal proved are the
 * signed-in user's in the current organization (Modules\Tenants\Portal,
 * the "linked to my user" path of ARCHITECTURE.md § 4) and answers only
 * for receipts issued to them: any other receipt id — another tenant's, or
 * another organization's, which the organization scope hides anyway — is
 * simply not found (null), so ID guessing gets the same answer as a
 * missing receipt. Answers for the current organization only
 * (OrganizationContext). No permission check: the caller checks its
 * policy before it opens a file. Bound in PaymentsServiceProvider.
 */
interface TenantReceipts
{
    /**
     * The receipts issued to these tenant profiles, newest first.
     *
     * @param  list<int>  $tenantProfileIds
     * @return list<TenantReceipt>
     */
    public function forTenants(array $tenantProfileIds): array;

    /**
     * One of their receipts, or null.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function find(int $receiptId, array $tenantProfileIds): ?TenantReceipt;

    /**
     * One of their receipts as a web page, as issued (with the VOID
     * watermark and reason once voided), or null.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function page(int $receiptId, array $tenantProfileIds): ?string;

    /**
     * One of their receipts' kept PDF (the evidence file; rendered now,
     * once, when the `pdf` queue has not yet), or null. With $void, a voided
     * receipt's VOID copy (null before a void).
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function pdf(int $receiptId, array $tenantProfileIds, bool $void = false): ?Media;
}
