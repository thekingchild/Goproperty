<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Media\Media;
use App\Models\User;
use Closure;
use Modules\Billing\Contracts\TenantBill;
use Modules\Billing\Contracts\TenantBills;
use Modules\Leasing\Contracts\TenantLeaseContracts;
use Modules\Payments\Contracts\TenantProofs;
use Modules\Payments\Contracts\TenantReceipt;
use Modules\Payments\Contracts\TenantReceipts;

/**
 * The tenant's documents in the portal (P03-11b; BRANDING_DOCUMENTS.md § 5,
 * ARCHITECTURE.md § 9): per agency, the lease contracts, the invoices and
 * the receipts (with their VOID copies), and opening each one — and the
 * files of their own proofs.
 *
 * Every file is found among the user's own records in one of their
 * organizations (Portal\PortalTenancies, then the Leasing, Billing and
 * Payments contracts limited to the user's tenant profiles there), checked
 * against TenantProfilePolicy::downloadAsTenant for the record's tenant
 * (anyone else's record: 404, like a missing one; while GoProperty staff
 * impersonate: 403), and only then handed to the controller, which sends a
 * short-lived signed link (App\Media\MediaUrls). The files are the kept
 * evidence files members open too, never re-rendered once kept.
 */
final readonly class PortalDocuments
{
    public function __construct(
        private PortalTenancies $tenancies,
        private PortalRentals $rentals,
        private PortalChecks $checks,
        private TenantBills $bills,
        private TenantReceipts $receipts,
        private TenantProofs $proofs,
        private TenantLeaseContracts $contracts,
    ) {}

    /** @return list<AgencyRecords> by agency name */
    public function of(User $user): array
    {
        return $this->tenancies->map($user, fn (PortalTenancy $tenancy): AgencyRecords => new AgencyRecords(
            agency: AgencyRentals::of($tenancy, $this->rentals->leasesOf($tenancy), $this->bills->forTenants($tenancy->tenantProfileIds)),
            proofs: [],
            receipts: $this->receipts->forTenants($tenancy->tenantProfileIds),
            files: $tenancy->tenantProfileIds !== [] && $this->checks->allows($user, 'downloadAsTenant', $tenancy->tenantProfileIds[0]),
        ));
    }

    /** One of the user's bills' invoice PDF (as issued, or the VOID copy of a void bill), or null. */
    public function invoicePdf(User $user, int $billId): ?Media
    {
        return $this->open($user, function (PortalTenancy $tenancy) use ($billId): ?array {
            $bill = $this->bills->find($billId, $tenancy->tenantProfileIds);

            return $bill instanceof TenantBill
                ? [$bill->tenantProfileId, fn (): ?Media => $this->bills->invoicePdf($billId, $tenancy->tenantProfileIds)]
                : null;
        });
    }

    /** One of the user's leases' contract PDF (activated leases only), or null. */
    public function contractPdf(User $user, int $leaseId): ?Media
    {
        return $this->open($user, function (PortalTenancy $tenancy) use ($leaseId): ?array {
            foreach ($this->rentals->leasesOf($tenancy) as $lease) {
                if ($lease->lease->id === $leaseId && $lease->lease->contractNumber !== null) {
                    return [$lease->lease->tenantProfileId, fn (): ?Media => $this->contracts->pdf($leaseId, $tenancy->tenantProfileIds)];
                }
            }

            return null;
        });
    }

    /** One of the user's receipts as a printed web page (with the VOID watermark once voided), or null. */
    public function receiptPage(User $user, int $receiptId): ?string
    {
        return $this->open($user, function (PortalTenancy $tenancy) use ($receiptId): ?array {
            $receipt = $this->receipts->find($receiptId, $tenancy->tenantProfileIds);

            return $receipt instanceof TenantReceipt
                ? [$receipt->tenantProfileId, fn (): ?string => $this->receipts->page($receiptId, $tenancy->tenantProfileIds)]
                : null;
        });
    }

    /** One of the user's receipts' kept PDF, or (with $void) its VOID copy; null otherwise. */
    public function receiptPdf(User $user, int $receiptId, bool $void = false): ?Media
    {
        return $this->open($user, function (PortalTenancy $tenancy) use ($receiptId, $void): ?array {
            $receipt = $this->receipts->find($receiptId, $tenancy->tenantProfileIds);

            if (! $receipt instanceof TenantReceipt || ($void && ! $receipt->voided)) {
                return null;
            }

            return [$receipt->tenantProfileId, fn (): ?Media => $this->receipts->pdf($receiptId, $tenancy->tenantProfileIds, $void)];
        });
    }

    /** One of the user's proofs' file, or null. */
    public function proofFile(User $user, int $proofId): ?Media
    {
        return $this->open($user, function (PortalTenancy $tenancy) use ($proofId): ?array {
            foreach ($this->proofs->forTenants($tenancy->tenantProfileIds) as $proof) {
                if ($proof->id === $proofId && $proof->hasFile) {
                    return [$proof->tenantProfileId, fn (): ?Media => $this->proofs->file($proofId, $tenancy->tenantProfileIds)];
                }
            }

            return null;
        });
    }

    /**
     * Finds the record among the user's tenancies ($find answers the
     * record's tenant profile id and how to open it, or null), checks
     * downloadAsTenant for that tenant, then opens it inside the record's
     * organization.
     *
     * @template T
     *
     * @param  Closure(PortalTenancy): (array{int, Closure(): (T|null)}|null)  $find
     * @return T|null
     */
    private function open(User $user, Closure $find): mixed
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $find): mixed {
            $record = $find($tenancy);

            if ($record === null) {
                return null;
            }

            [$tenantProfileId, $open] = $record;
            $this->checks->authorize($user, 'downloadAsTenant', $tenantProfileId);

            return $open() ?? abort(404);
        });

        return $found[1] ?? null;
    }
}
