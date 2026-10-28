<?php

declare(strict_types=1);

namespace Modules\Billing\Documents;

use App\Banking\BankAccounts;
use App\Documents\BrandingSnapshot;
use App\Documents\Document;
use App\Documents\DocumentBranding;
use App\Documents\DocumentRenderer;
use App\Documents\Invoice\BankTransfer;
use App\Documents\Invoice\InvoiceDocument;
use App\Documents\Invoice\InvoiceLine;
use App\Documents\Invoice\InvoiceStatus;
use App\Documents\Invoice\OnlinePayment;
use App\Documents\Parts\DocumentPeriod;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Media\Media;
use App\Models\OrganizationBankAccount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Billing\Bills\BillDetails;
use Modules\Billing\Contracts\PayableBill;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Billing\Utilities\MeterValue;

/**
 * A bill's invoice (P03-05c; BRANDING_DOCUMENTS.md § 2, § 5; ARCHITECTURE.md
 * § 6.5): Core's InvoiceDocument built from the bill as issued — the
 * branding snapshot and the parties taken at issue (`branding_snapshot`,
 * `details`), its number, lines, reference and verification code — and its
 * money and status as they are now (paid to date, the status badge, VOID
 * with its reason). How to pay: the organization's primary usable bank
 * account (ONBOARDING_COMPLIANCE.md § 4.1, P01-05) and "Pay online" when a
 * payment option (Contracts\PaymentOptions) offers the bill.
 *
 * pdf(): the invoice's PDF, rendered once (DocumentRenderer::stored()) into
 * the bill's `issued_documents` media and kept as evidence: key `invoice`
 * for the invoice as issued (queued on `pdf` right after the bill is
 * issued, Jobs\RenderInvoice), key `invoice-void` for the VOID copy of a
 * void bill (queued when it is voided). Every later download returns the
 * kept file; a change of branding, names or templates never alters it.
 */
final readonly class Invoices
{
    /** The kept PDF of the invoice as issued. */
    public const string DOCUMENT_KEY = 'invoice';

    /** The kept PDF of a void bill's invoice, with the VOID watermark and the reason. */
    public const string VOID_KEY = 'invoice-void';

    public function __construct(
        private DocumentRenderer $renderer,
        private BillDetails $details,
        private BankAccounts $banks,
        private PaymentOptions $options,
    ) {}

    /** The bill's branding as issued (the snapshot taken by Bills\BillIssuer). */
    public function brand(Bill $bill): DocumentBranding
    {
        return BrandingSnapshot::fromArray($bill->branding_snapshot)->documentBranding();
    }

    /**
     * What the bill prints as now: its invoice, or for a utility bill the
     * utility bill built on it (P03-06b).
     */
    public function document(Bill $bill): Document
    {
        $invoice = $this->invoice($bill);

        return $bill->type === BillType::Utility ? $this->utilityBill($invoice, $this->items($bill)) : $invoice;
    }

    /** The invoice as printed now (current status and payments, branding and parties as issued). */
    public function invoice(Bill $bill): InvoiceDocument
    {
        $parties = $this->details->of($bill);
        $period = $bill->period_start !== null && $bill->period_end !== null ? new DocumentPeriod($bill->period_start, $bill->period_end) : null;
        $payable = $this->payable($bill);
        $account = $payable !== null ? $this->banks->primary() : null;

        return new InvoiceDocument(
            brand: $this->brand($bill),
            number: $bill->number,
            issuedOn: $bill->issued_at,
            dueOn: $bill->due_date,
            status: InvoiceStatus::from($bill->status->value),
            tenant: new DocumentTenant($parties->tenantName, $parties->tenantPhone, $parties->tenantEmail),
            property: new DocumentProperty($parties->buildingName ?? '', $parties->unit, $parties->address, $parties->leaseNumber, $parties->cycle?->label()),
            lines: $this->lines($bill),
            paymentReference: $bill->payment_reference,
            verificationCode: $bill->verification_code,
            bank: $account instanceof OrganizationBankAccount ? new BankTransfer($account->bank_name, $account->account_name, $account->account_number) : null,
            online: $payable !== null && $this->options->for($payable) !== [] ? new OnlinePayment(route('pay.show', $bill->payment_reference)) : null,
            period: $period,
            paidToDate: $bill->paid_kobo,
            voidReason: $bill->status === BillStatus::Void ? ($bill->void_reason ?? '') : null,
        );
    }

    /** The bill as payment options see it, while it still asks for money; null once paid or void. */
    public function payable(Bill $bill): ?PayableBill
    {
        $outstanding = $bill->outstanding();

        if (! $bill->status->isOpen() || ! $outstanding->isPositive()) {
            return null;
        }

        return new PayableBill(
            id: $bill->id,
            organizationId: $bill->organization_id,
            tenantProfileId: $bill->tenant_profile_id,
            number: $bill->number,
            paymentReference: $bill->payment_reference,
            amount: $bill->amount_kobo,
            outstanding: $outstanding,
            dueDate: $bill->due_date,
        );
    }

    /** Which kept PDF stands for the bill now: the VOID copy once it is void, else the invoice as issued. */
    public function key(Bill $bill): string
    {
        return $bill->status === BillStatus::Void ? self::VOID_KEY : self::DOCUMENT_KEY;
    }

    /**
     * The invoice's PDF: the kept file for the bill's current key, or
     * rendered and kept now (once, under a lock on the bill row). The first
     * PDF's media id is recorded on the bill.
     */
    public function pdf(Bill $bill): Media
    {
        $media = $this->renderer->stored($bill, $this->key($bill), $this->document($bill));

        if ($bill->document_media_id === null) {
            Bill::query()->whereKey($bill->id)->whereNull('document_media_id')->update(['document_media_id' => $media->id]);
        }

        return $media;
    }

    /** The kept PDF for the bill's current key, if it has been rendered. */
    public function kept(Bill $bill): ?Media
    {
        return $this->renderer->kept($bill, $this->key($bill));
    }

    /**
     * A utility bill from its invoice and its lines as issued: the utility's
     * name is the lines' label, and a metered bill's lines carry their
     * reading in `meta` (Bills\UtilityBillGenerator).
     *
     * @param  Collection<int, BillItem>  $items
     */
    private function utilityBill(InvoiceDocument $invoice, Collection $items): UtilityBillDocument
    {
        $first = $items->firstOrFail();
        $meta = $first->meta ?? [];
        $metered = ($meta['charging'] ?? null) === 'metered';
        $unit = __((MeterUnit::tryFrom(is_string($meta['unit'] ?? null) ? $meta['unit'] : '') ?? MeterUnit::Unit)->label());

        if (! $metered) {
            return new UtilityBillDocument(
                invoice: $invoice,
                utility: $first->label,
                charging: $first->note ?? __('Flat charge'),
                metered: false,
            );
        }

        return new UtilityBillDocument(
            invoice: $invoice,
            utility: $first->label,
            charging: __('Metered, per :unit', ['unit' => $unit]),
            metered: true,
            unit: $unit,
            readings: array_values($items->map(static function (BillItem $item) use ($unit): UtilityReadingLine {
                $meta = $item->meta ?? [];
                $value = static fn (string $key): string => MeterValue::parse(is_string($meta[$key] ?? null) ? $meta[$key] : null)?->format() ?? '';

                return new UtilityReadingLine(
                    readOn: is_string($meta['reading_date'] ?? null) ? CarbonImmutable::parse($meta['reading_date']) : ($item->period_end ?? CarbonImmutable::today()),
                    previous: $value('previous'),
                    current: $value('current'),
                    consumption: $value('consumption'),
                    unit: $unit,
                    rate: Money::kobo(is_int($meta['rate_kobo'] ?? null) ? $meta['rate_kobo'] : 0),
                    amount: $item->amount_kobo,
                    meterReplaced: ($meta['meter_replaced'] ?? false) === true,
                );
            })->all()),
        );
    }

    /** @return Collection<int, BillItem> */
    private function items(Bill $bill): Collection
    {
        return BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->get();
    }

    /** @return list<InvoiceLine> */
    private function lines(Bill $bill): array
    {
        return array_values($this->items($bill)
            ->map(static fn (BillItem $item): InvoiceLine => new InvoiceLine(
                description: $item->label,
                amount: $item->amount_kobo,
                note: $item->note,
                period: $item->period_start !== null && $item->period_end !== null ? new DocumentPeriod($item->period_start, $item->period_end) : null,
            ))
            ->all());
    }
}
