<?php

declare(strict_types=1);

namespace App\Documents\Verification;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What the public verify page (/verify/{code}) may show about a document
 * found by its code: enough to tell a genuine document from a fake or
 * edited one (BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7: the agency's
 * name, the document, its date and amount, and whether it is valid, voided
 * or superseded), nothing more. A resolver builds it from the document as
 * issued (its branding snapshot gives the issuer's name) and its current
 * state (paid, voided, …). No tenant names or contact details, no bank
 * details: anyone holding the paper or a guessed link sees this page.
 */
final readonly class VerifiedDocument
{
    /**
     * @param  string  $title  e.g. "Official receipt", "Invoice"
     * @param  string  $issuer  the organization's name as printed on the document
     * @param  ?string  $statusNote  e.g. "Voided on 15/12/2026" (no names, no reasons that may name people)
     * @param  array<string, string>  $details  a few more label => value lines about the document itself (e.g. "Status" => "Paid"); never people's names or contact details, nor bank details
     * @param  ?string  $replacedBy  the number of the document that superseded this one
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $number,
        public string $issuer,
        public CarbonImmutable $issuedAt,
        public VerificationStatus $status,
        public ?Money $amount = null,
        public ?string $statusNote = null,
        public array $details = [],
        public ?string $replacedBy = null,
    ) {}

    public function isVoided(): bool
    {
        return $this->status === VerificationStatus::Voided;
    }

    public function isSuperseded(): bool
    {
        return $this->status === VerificationStatus::Superseded;
    }
}
