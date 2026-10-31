<?php

declare(strict_types=1);

namespace Modules\Billing\Pay;

use App\Banking\BankAccounts;
use App\Documents\Invoice\BankTransfer;
use App\Documents\Parts\DocumentDates;
use App\Documents\Parts\DocumentPeriod;
use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Bills\BillDetails;
use Modules\Billing\Bills\PaymentReferences;
use Modules\Billing\Contracts\PaymentOption;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Payments\Contracts\PartPayments;

/**
 * Finds a bill by its payment reference for the public pay page
 * (`/pay/{reference}`, P03-05c; BRANDING_DOCUMENTS.md § 1 "Pay page",
 * ONBOARDING_COMPLIANCE.md § 4.1), with no organization context: one
 * unscoped read of `bills.organization_id` by the unique reference (the
 * P01-04 invitation pattern, orchestrator decision 2; no scope removed),
 * then the bill itself is read inside that organization
 * (OrganizationContext::run). References are unique across
 * organizations, so a reference only ever shows its own organization's
 * bill. Bills removed by an import rollback are not found.
 *
 * The page shows the bill's branding as issued (the invoice's snapshot),
 * its money now, the organization's primary usable bank account now
 * (P01-05: an account whose name the bank disputes is never shown) and
 * the online payment options that offer it (Contracts\PaymentOptions).
 */
final readonly class PayPages
{
    public function __construct(
        private OrganizationContext $context,
        private Invoices $invoices,
        private BillDetails $details,
        private BankAccounts $banks,
        private PaymentOptions $options,
    ) {}

    /** The pay page of the reference (in its stored form, PaymentReferences::normalize()), or null. */
    public function find(string $reference): ?PayPage
    {
        if (PaymentReferences::normalize($reference) !== $reference) {
            return null;
        }

        $organizationId = DB::table('bills')->where('payment_reference', $reference)->whereNull('deleted_at')->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if (! $organization instanceof Organization) {
            return null;
        }

        return $this->context->run($organization, function () use ($reference): ?PayPage {
            $bill = Bill::query()->where('payment_reference', $reference)->first();

            return $bill instanceof Bill ? $this->page($bill) : null;
        });
    }

    private function page(Bill $bill): PayPage
    {
        $parties = $this->details->of($bill);
        $payable = $this->invoices->payable($bill);
        $account = $payable !== null ? $this->banks->primary() : null;
        $period = static fn (?CarbonImmutable $start, ?CarbonImmutable $end): ?string => $start !== null && $end !== null ? (new DocumentPeriod($start, $end))->label() : null;
        $property = $parties->buildingName !== null ? ($parties->unit !== null ? $parties->buildingName.' — '.$parties->unit : $parties->buildingName) : $parties->unit;

        return new PayPage(
            brand: $this->invoices->brand($bill),
            number: $bill->number,
            reference: $bill->payment_reference,
            status: $bill->status,
            issuedOn: DocumentDates::day($bill->issued_at),
            dueOn: DocumentDates::day($bill->due_date),
            period: $period($bill->period_start, $bill->period_end),
            property: $property,
            lines: array_values(BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->get()
                ->map(static fn (BillItem $item): array => [
                    'label' => $item->label,
                    'note' => $item->note,
                    'period' => $period($item->period_start, $item->period_end),
                    'amount' => $item->amount_kobo->format(),
                ])->all()),
            amount: $bill->amount_kobo,
            paid: $bill->paid_kobo,
            outstanding: $bill->outstanding(),
            bank: $account instanceof OrganizationBankAccount ? new BankTransfer($account->bank_name, $account->account_name, $account->account_number) : null,
            options: $payable === null ? [] : array_map(static fn (PaymentOption $option): string => $option->render($payable)->toHtml(), $this->options->for($payable)),
            voidedOn: $bill->voided_at !== null ? DocumentDates::day($bill->voided_at) : null,
            // P03-17: the Payments module counts the bill's payments against its lease's limit.
            partPayments: app()->bound(PartPayments::class) ? app(PartPayments::class)->forBill($bill->id)?->describe() : null,
        );
    }
}
