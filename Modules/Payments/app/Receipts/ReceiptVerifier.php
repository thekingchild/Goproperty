<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use App\Documents\Parts\DocumentDates;
use App\Documents\Verification\VerificationResolver;
use App\Documents\Verification\VerificationStatus;
use App\Documents\Verification\VerifiedDocument;
use App\Models\Organization;
use Modules\Payments\Models\Receipt;

/**
 * Receipts on the public verify page (P03-10b; kind `receipt`, registered
 * into DocumentVerifiers by PaymentsServiceProvider; BRANDING_DOCUMENTS.md
 * § 3 item 7): the issuer as printed, the number, date, amount and whether
 * the receipt is **valid** or **voided** (with the day it was voided). Runs
 * inside the issuing organization. Never the tenant, the property, the
 * reason or any bank detail: anyone holding the paper sees this page.
 */
final readonly class ReceiptVerifier implements VerificationResolver
{
    public function __construct(private Receipts $receipts) {}

    public function resolve(string $code, Organization $organization): ?VerifiedDocument
    {
        $receipt = Receipt::query()->where('verification_code', $code)->first();

        if (! $receipt instanceof Receipt) {
            return null;
        }

        // The receipt as printed: its issuer and payment as at issue.
        $printed = $this->receipts->document($receipt);

        return new VerifiedDocument(
            kind: Receipts::KIND,
            title: 'Official receipt',
            number: $receipt->number,
            issuer: $printed->brand->name(),
            issuedAt: $receipt->issued_at,
            status: $receipt->isVoided() ? VerificationStatus::Voided : VerificationStatus::Valid,
            amount: $receipt->amount_kobo,
            statusNote: $receipt->voided_at !== null ? 'Voided on '.DocumentDates::day($receipt->voided_at) : null,
            details: [
                'Paid on' => DocumentDates::day($printed->payment->paidOn),
                'Method' => $printed->payment->method,
            ],
        );
    }
}
