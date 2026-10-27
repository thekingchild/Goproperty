<?php

declare(strict_types=1);

namespace App\Documents\Verification;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What the public verify page (P03-05b) may show about a document found by
 * its code: enough to tell a genuine document from a fake or edited one
 * (BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7), nothing more. A resolver
 * builds it from the document as issued (its branding snapshot gives the
 * issuer's name) and its current state (paid, voided, …). No tenant
 * contact details, no bank details.
 */
final readonly class VerifiedDocument
{
    /**
     * @param  string  $title  e.g. "Official receipt", "Invoice"
     * @param  string  $issuer  the organization's name as printed on the document
     * @param  array<string, string>  $details  a few more label => value lines (e.g. "Status" => "Paid")
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
    ) {}

    public function isVoided(): bool
    {
        return $this->status === VerificationStatus::Voided;
    }
}
