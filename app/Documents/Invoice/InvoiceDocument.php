<?php

declare(strict_types=1);

namespace App\Documents\Invoice;

use App\Documents\Document;
use App\Documents\DocumentBranding;
use App\Documents\Parts\DocumentDates;
use App\Documents\Parts\DocumentPeriod;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Verification\VerificationCodes;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An invoice as printed (BRANDING_DOCUMENTS.md § 2; design:
 * docs/design/templates/invoice.html), in the organization's layout
 * (resources/views/documents/invoice/{modern,classic,compact}). Core knows
 * no bills: Billing builds one of these from a bill, its lines, the branding
 * snapshot taken at issue (BrandingSnapshot::documentBranding()) and the
 * bill's current status, and renders it with DocumentRenderer.
 *
 * Amounts are Money; the subtotal is the sum of the lines, and the amount
 * due is the subtotal less credit applied and payments so far (never below
 * zero; nothing on a void invoice). A void invoice prints a diagonal VOID watermark with the reason.
 */
final readonly class InvoiceDocument implements Document
{
    /** @var list<InvoiceLine> */
    public array $lines;

    public Money $credit;

    public Money $paidToDate;

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  string  $paymentReference  the bill's reference for transfer narrations ("GP-7KQ2M")
     * @param  ?BankTransfer  $bank  null when the organization has no account to show yet
     * @param  ?DocumentPeriod  $period  the bill's period, printed on lines that have none of their own
     */
    public function __construct(
        public DocumentBranding $brand,
        public string $number,
        public CarbonImmutable $issuedOn,
        public CarbonImmutable $dueOn,
        public InvoiceStatus $status,
        public DocumentTenant $tenant,
        public DocumentProperty $property,
        array $lines,
        public string $paymentReference,
        public string $verificationCode,
        public ?BankTransfer $bank = null,
        public ?OnlinePayment $online = null,
        public ?DocumentPeriod $period = null,
        ?Money $credit = null,
        ?Money $paidToDate = null,
        public ?string $voidReason = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice has at least one line.');
        }

        if (VerificationCodes::normalize($verificationCode) === null) {
            throw new InvalidArgumentException('An invoice needs its verification code (VerificationCodes::issue()).');
        }

        $this->lines = $lines;
        $this->credit = $credit ?? Money::zero();
        $this->paidToDate = $paidToDate ?? Money::zero();
    }

    public function view(): string
    {
        return 'documents.invoice.'.$this->brand->layout()->value;
    }

    public function data(): array
    {
        return ['invoice' => $this];
    }

    public function brand(): DocumentBranding
    {
        return $this->brand;
    }

    public function fileName(): string
    {
        return $this->number.'.pdf';
    }

    public function subtotal(): Money
    {
        return Money::sum(array_map(static fn (InvoiceLine $line): Money => $line->amount, $this->lines));
    }

    public function amountDue(): Money
    {
        if ($this->isVoid()) {
            return Money::zero();
        }

        return Money::max(Money::zero(), $this->subtotal()->minus($this->credit)->minus($this->paidToDate));
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    /** Whether to print how to pay: not on paid, void or draft invoices, nor when nothing is due. */
    public function showsPayment(): bool
    {
        return $this->status->isPayable() && $this->amountDue()->isPositive();
    }

    public function issuedOnLabel(): string
    {
        return DocumentDates::day($this->issuedOn);
    }

    public function dueOnLabel(): string
    {
        return DocumentDates::day($this->dueOn);
    }

    public function dueOnLong(): string
    {
        return DocumentDates::long($this->dueOn);
    }

    /** The footer's "company · document" part. */
    public function footerLine(): string
    {
        return $this->brand->branding->companyName.' · Invoice '.$this->number;
    }
}
