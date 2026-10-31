<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Contracts\TenantBill;
use Modules\Billing\Contracts\TenantBills;
use Modules\Payments\Contracts\PartPayments;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Contracts\SubmittedProof;
use Modules\Payments\Contracts\TenantProofs;
use Modules\Payments\Contracts\TenantReceipt;
use Modules\Payments\Contracts\TenantReceipts;

/**
 * The tenant portal's payments (P03-11b): the proofs the user sent and where
 * each stands, the receipts issued to them, one receipt's page, and sending
 * a proof for one of their bills. Read only through Portal\PortalTenancies
 * (the "linked to my user" path) and inside each agency's organization,
 * through the Billing and Payments contracts (ARCHITECTURE.md § 8 rule 3),
 * limited to the user's own tenant profiles there; every record is checked
 * against TenantProfilePolicy::viewAsTenant for its tenant (anyone else's:
 * 404).
 *
 * A proof goes through Payments' Contracts\PaymentProofs::submitForBill
 * (P03-09), which checks the bill again (PaymentProofPolicy::submitAsTenant:
 * the tenant's own, still asking for money; allowed while the agency is
 * suspended, never while staff impersonate), validates the file, applies
 * the rate limits and records the payment waiting for confirmation.
 */
final readonly class PortalPayments
{
    public function __construct(
        private PortalTenancies $tenancies,
        private PortalChecks $checks,
        private TenantBills $bills,
        private TenantProofs $proofs,
        private TenantReceipts $receipts,
        private PaymentProofs $paymentProofs,
        private PartPayments $partPayments,
    ) {}

    /** @return list<AgencyRecords> by agency name */
    public function of(User $user): array
    {
        return $this->tenancies->map($user, fn (PortalTenancy $tenancy): AgencyRecords => new AgencyRecords(
            agency: AgencyRentals::of($tenancy, [], $this->bills->forTenants($tenancy->tenantProfileIds)),
            proofs: $this->proofs->forTenants($tenancy->tenantProfileIds),
            receipts: $this->receipts->forTenants($tenancy->tenantProfileIds),
            files: $this->files($user, $tenancy),
        ));
    }

    /** One of the user's receipts with its agency, or null (the page answers 404). */
    public function receipt(User $user, int $receiptId): ?PortalReceiptPage
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $receiptId): ?PortalReceiptPage {
            $receipt = $this->receipts->find($receiptId, $tenancy->tenantProfileIds);

            if (! $receipt instanceof TenantReceipt) {
                return null;
            }

            $this->checks->authorize($user, 'viewAsTenant', $receipt->tenantProfileId);

            return new PortalReceiptPage(
                agency: AgencyRentals::of($tenancy, [], $this->bills->forTenants($tenancy->tenantProfileIds)),
                receipt: $receipt,
                files: $this->checks->allows($user, 'downloadAsTenant', $receipt->tenantProfileId),
            );
        });

        return $found[1] ?? null;
    }

    /** The proof page of one of the user's bills, or null (404). */
    public function proofPage(User $user, int $billId): ?PortalProofPage
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $billId): ?PortalProofPage {
            $bill = $this->bills->find($billId, $tenancy->tenantProfileIds);

            if (! $bill instanceof TenantBill) {
                return null;
            }

            $this->checks->authorize($user, 'viewAsTenant', $bill->tenantProfileId);

            return new PortalProofPage(
                agency: AgencyRentals::of($tenancy),
                bill: $bill,
                maySubmit: $bill->asksForMoney() && $this->paymentProofs->maySubmit($user, $billId),
                partPayments: $bill->asksForMoney() ? $this->partPayments->forBill($billId)?->forNextPayment() : null,
            );
        });

        return $found[1] ?? null;
    }

    /**
     * Sends the user's proof for one of their bills (PaymentProofs input:
     * amount, paidOn, senderName, bank, reference, note), inside the bill's
     * organization; null when the bill is not theirs (404).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException keyed by the input names, `file`, or `proof` (rate limits)
     */
    public function submitProof(User $user, int $billId, array $input, UploadedFile $file): ?SubmittedProof
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $billId, $input, $file): ?SubmittedProof {
            $bill = $this->bills->find($billId, $tenancy->tenantProfileIds);

            if (! $bill instanceof TenantBill) {
                return null;
            }

            $this->checks->authorize($user, 'viewAsTenant', $bill->tenantProfileId);

            return $this->paymentProofs->submitForBill($user, $billId, $input, $file);
        });

        return $found[1] ?? null;
    }

    /** Whether the tenancy's files may be opened now (not while staff impersonate). Inside its organization. */
    private function files(User $user, PortalTenancy $tenancy): bool
    {
        return $tenancy->tenantProfileIds !== [] && $this->checks->allows($user, 'downloadAsTenant', $tenancy->tenantProfileIds[0]);
    }
}
