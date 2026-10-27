<?php

declare(strict_types=1);

namespace App\Documents\Receipt;

use App\Documents\Document;
use App\Documents\DocumentBranding;
use App\Documents\Parts\DocumentDates;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Verification\VerificationCodes;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An official receipt as printed (BRANDING_DOCUMENTS.md § 3; design:
 * docs/design/templates/receipt.html), in the organization's layout
 * (resources/views/documents/receipt/{modern,classic,compact}). Payments
 * builds one from a receipt, its payment and allocations and the branding
 * snapshot taken at issue, and renders it with DocumentRenderer.
 *
 * A voided receipt ($voidedAt set) is re-issued with a diagonal VOID
 * watermark and the void reason in place of the PAID stamp (§ 3; stored
 * under its own document key, e.g. "receipt-void").
 */
final readonly class ReceiptDocument implements Document
{
    /** @var list<ReceiptAllocation> */
    public array $allocations;

    /**
     * @param  list<ReceiptAllocation>  $allocations
     * @param  string  $confirmedBy  the person who confirmed it, or "Automatically — Paystack" / "Statement match"
     * @param  list<string>  $sentVia  how a copy reached the tenant ("WhatsApp", "email"); none: the sentence is left out
     */
    public function __construct(
        public DocumentBranding $brand,
        public string $number,
        public CarbonImmutable $issuedAt,
        public Money $amount,
        public DocumentTenant $tenant,
        public DocumentProperty $property,
        public ReceiptPayment $payment,
        array $allocations,
        public Money $outstandingAfter,
        public Money $creditBalance,
        public string $confirmedBy,
        public CarbonImmutable $confirmedAt,
        public string $verificationCode,
        public array $sentVia = [],
        public ?CarbonImmutable $voidedAt = null,
        public ?string $voidReason = null,
    ) {
        if (VerificationCodes::normalize($verificationCode) === null) {
            throw new InvalidArgumentException('A receipt needs its verification code (VerificationCodes::issue()).');
        }

        if (($voidedAt === null) !== ($voidReason === null || trim($voidReason) === '')) {
            throw new InvalidArgumentException('A voided receipt has both the time it was voided and the reason.');
        }

        $this->allocations = $allocations;
    }

    public function view(): string
    {
        return 'documents.receipt.'.$this->brand->layout()->value;
    }

    public function data(): array
    {
        return ['receipt' => $this];
    }

    public function brand(): DocumentBranding
    {
        return $this->brand;
    }

    public function fileName(): string
    {
        return $this->number.($this->isVoid() ? '-void' : '').'.pdf';
    }

    public function isVoid(): bool
    {
        return $this->voidedAt !== null;
    }

    public function totalApplied(): Money
    {
        return Money::sum(array_map(static fn (ReceiptAllocation $allocation): Money => $allocation->amount, $this->allocations));
    }

    public function issuedOnLabel(): string
    {
        return DocumentDates::day($this->issuedAt);
    }

    public function confirmedAtLabel(): string
    {
        return DocumentDates::time($this->confirmedAt);
    }

    public function voidedAtLabel(): ?string
    {
        return $this->voidedAt !== null ? DocumentDates::time($this->voidedAt) : null;
    }

    /** "A copy was sent to the tenant by WhatsApp and email.", or null. */
    public function sentSentence(): ?string
    {
        $channels = array_values(array_filter($this->sentVia, static fn (string $channel): bool => trim($channel) !== ''));

        if ($channels === []) {
            return null;
        }

        $last = array_pop($channels);

        return 'A copy was sent to the tenant by '.($channels === [] ? $last : implode(', ', $channels).' and '.$last).'.';
    }

    /** The footer's "company · document" part. */
    public function footerLine(): string
    {
        return $this->brand->branding->companyName.' · Receipt '.$this->number;
    }
}
