<?php

declare(strict_types=1);

namespace Modules\Billing\Documents;

use App\Documents\Parts\DocumentDates;
use App\Documents\Parts\DocumentPeriod;
use App\Documents\Verification\VerificationResolver;
use App\Documents\Verification\VerificationStatus;
use App\Documents\Verification\VerifiedDocument;
use App\Models\Organization;
use Modules\Billing\Bills\BillIssuer;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;

/**
 * Invoices on the public verify page (P03-05c; kind `invoice`, registered
 * into DocumentVerifiers by BillingServiceProvider; BRANDING_DOCUMENTS.md
 * § 2 item 8: "confirms the invoice is genuine and shows its current
 * status"). Runs inside the issuing organization.
 *
 * A genuine invoice is **valid** with its payment status as it is now
 * (Unpaid, Partly paid, Paid, Overdue), its due date and period; a void
 * bill's invoice is **voided** ("Voided on …"; the reason is on the
 * document, not on the public page). Only the invoice itself: issuer as
 * printed, number, issue date and amount — never the tenant, the unit or
 * the address, nor the bank details or the payment reference.
 */
final readonly class InvoiceVerifier implements VerificationResolver
{
    public function __construct(private Invoices $invoices) {}

    public function resolve(string $code, Organization $organization): ?VerifiedDocument
    {
        $bill = Bill::query()->where('verification_code', $code)->first();

        if (! $bill instanceof Bill) {
            return null;
        }

        $void = $bill->status === BillStatus::Void;
        $details = [];

        if (! $void) {
            $details['Payment status'] = $bill->status->label();
            $details['Due'] = DocumentDates::day($bill->due_date);
        }

        if ($bill->period_start !== null && $bill->period_end !== null) {
            $details['Period'] = (new DocumentPeriod($bill->period_start, $bill->period_end))->label();
        }

        return new VerifiedDocument(
            kind: BillIssuer::VERIFICATION_KIND,
            title: 'Invoice',
            number: $bill->number,
            issuer: $this->invoices->brand($bill)->name(),
            issuedAt: $bill->issued_at,
            status: $void ? VerificationStatus::Voided : VerificationStatus::Valid,
            amount: $bill->amount_kobo,
            statusNote: $void && $bill->voided_at !== null ? 'Voided on '.DocumentDates::day($bill->voided_at) : null,
            details: $details,
        );
    }
}
