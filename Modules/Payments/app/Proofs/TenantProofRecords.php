<?php

declare(strict_types=1);

namespace Modules\Payments\Proofs;

use App\Media\Media;
use Modules\Payments\Contracts\TenantProof;
use Modules\Payments\Contracts\TenantProofs;
use Modules\Payments\Enums\ProofStatus;
use Modules\Payments\Enums\ProofSubmitter;
use Modules\Payments\Models\PaymentProof;

/**
 * The Payments module's answers to Contracts\TenantProofs (the tenant
 * portal, P03-11b): read through the organization scope and only among
 * the given tenants' proofs, so a guessed id of anyone else's proof is not
 * found. Where a proof stands is its payment's review (PaymentProof::
 * status). Read-only; the duplicate flag is never told to the tenant.
 */
final readonly class TenantProofRecords implements TenantProofs
{
    public function forTenants(array $tenantProfileIds): array
    {
        if ($tenantProfileIds === []) {
            return [];
        }

        return array_values(PaymentProof::query()
            ->with('payment')
            ->whereIn('tenant_profile_id', $tenantProfileIds)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->map(static function (PaymentProof $proof): TenantProof {
                $payment = $proof->payment;
                $status = $proof->status($payment);

                return new TenantProof(
                    id: $proof->id,
                    organizationId: $proof->organization_id,
                    tenantProfileId: $proof->tenant_profile_id,
                    billId: $proof->bill_id,
                    amount: $proof->amount_kobo,
                    paidOn: $proof->paid_on,
                    senderName: $proof->sender_name,
                    bankName: $proof->bank_name,
                    reference: $proof->reference,
                    submittedAt: $proof->submitted_at,
                    byTenant: $proof->submitted_by === ProofSubmitter::Tenant,
                    status: $status->value,
                    statusLabel: $status->label(),
                    statusColor: $status->color(),
                    rejectionReason: $status === ProofStatus::Rejected ? $payment?->rejection_reason : null,
                    receiptId: $status === ProofStatus::Accepted ? $payment?->receipt_id : null,
                    hasFile: $proof->media_id !== null,
                );
            })
            ->all());
    }

    public function file(int $proofId, array $tenantProfileIds): ?Media
    {
        if ($tenantProfileIds === []) {
            return null;
        }

        $proof = PaymentProof::query()->whereIn('tenant_profile_id', $tenantProfileIds)->whereKey($proofId)->first();
        $media = $proof instanceof PaymentProof && $proof->media_id !== null ? Media::query()->find($proof->media_id) : null;

        return $media instanceof Media ? $media : null;
    }
}
