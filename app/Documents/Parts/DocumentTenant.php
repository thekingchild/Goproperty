<?php

declare(strict_types=1);

namespace App\Documents\Parts;

/**
 * The tenant a document is for, as printed (BRANDING_DOCUMENTS.md § 2 item
 * 3 "Billed to", § 3 items 1 and 3): name, phone and email as the module
 * formats them, and the tenant's reference ("TN-0087") for receipts.
 * Documents only: the public verify page never shows any of it.
 */
final readonly class DocumentTenant
{
    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $reference = null,
    ) {}

    /** @return list<string> phone and email, those present */
    public function contactLines(): array
    {
        return array_values(array_filter([$this->phone, $this->email], static fn (?string $line): bool => $line !== null && $line !== ''));
    }
}
