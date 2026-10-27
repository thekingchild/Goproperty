<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use App\Documents\DocumentRenderer;
use App\Documents\Verification\VerificationCodes;
use App\Media\Media;
use Modules\Payments\Contracts\TenantReceipt;
use Modules\Payments\Contracts\TenantReceipts;
use Modules\Payments\Models\Receipt;

/**
 * The Payments module's answers to Contracts\TenantReceipts (the tenant
 * portal, P03-11b): read through the organization scope and only among the
 * given tenants' receipts, so a guessed id of anyone else's receipt is not
 * found. Each receipt is shown as it was issued (Receipts::document(): the
 * branding, parties and payment kept at issue), and its PDF is the kept
 * evidence file (Receipts::pdf(), rendered once) or, once voided, the
 * separate VOID copy (Receipts::voidPdf()) — the same files members open.
 */
final readonly class TenantReceiptRecords implements TenantReceipts
{
    public function __construct(
        private Receipts $receipts,
        private DocumentRenderer $renderer,
    ) {}

    public function forTenants(array $tenantProfileIds): array
    {
        if ($tenantProfileIds === []) {
            return [];
        }

        return array_values(Receipt::query()
            ->whereIn('tenant_profile_id', $tenantProfileIds)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Receipt $receipt): TenantReceipt => $this->summary($receipt))
            ->all());
    }

    public function find(int $receiptId, array $tenantProfileIds): ?TenantReceipt
    {
        $receipt = $this->receipt($receiptId, $tenantProfileIds);

        return $receipt instanceof Receipt ? $this->summary($receipt) : null;
    }

    public function page(int $receiptId, array $tenantProfileIds): ?string
    {
        $receipt = $this->receipt($receiptId, $tenantProfileIds);

        return $receipt instanceof Receipt ? $this->renderer->web($this->receipts->document($receipt, void: $receipt->isVoided())) : null;
    }

    public function pdf(int $receiptId, array $tenantProfileIds, bool $void = false): ?Media
    {
        $receipt = $this->receipt($receiptId, $tenantProfileIds);

        if (! $receipt instanceof Receipt) {
            return null;
        }

        if ($void) {
            return $receipt->isVoided() ? $this->receipts->voidPdf($receipt) : null;
        }

        return $this->receipts->pdf($receipt);
    }

    /** @param  list<int>  $tenantProfileIds */
    private function receipt(int $receiptId, array $tenantProfileIds): ?Receipt
    {
        if ($tenantProfileIds === []) {
            return null;
        }

        $receipt = Receipt::query()->whereIn('tenant_profile_id', $tenantProfileIds)->whereKey($receiptId)->first();

        return $receipt instanceof Receipt ? $receipt : null;
    }

    private function summary(Receipt $receipt): TenantReceipt
    {
        $billIds = [];

        foreach (is_array($receipt->details['allocations'] ?? null) ? $receipt->details['allocations'] : [] as $row) {
            $billId = is_array($row) ? ($row['bill_id'] ?? null) : null;

            if (is_int($billId) || (is_string($billId) && ctype_digit($billId))) {
                $billIds[] = (int) $billId;
            }
        }

        return new TenantReceipt(
            id: $receipt->id,
            organizationId: $receipt->organization_id,
            tenantProfileId: $receipt->tenant_profile_id,
            document: $this->receipts->document($receipt, void: $receipt->isVoided()),
            voided: $receipt->isVoided(),
            verifyUrl: VerificationCodes::url($receipt->verification_code),
            billIds: array_values(array_unique($billIds)),
        );
    }
}
