<?php

declare(strict_types=1);

namespace App\Documents\Parts;

/**
 * The unit a document is about (BRANDING_DOCUMENTS.md § 2 item 3
 * "Property"): building and unit, the building's address, the lease number
 * and its billing cycle ("Annual"), as the module formats them.
 */
final readonly class DocumentProperty
{
    public function __construct(
        public string $building,
        public ?string $unit = null,
        public ?string $address = null,
        public ?string $leaseNumber = null,
        public ?string $cycle = null,
    ) {}

    /** "Palm Court Apartments — Flat 3B" */
    public function name(): string
    {
        return $this->unit !== null && $this->unit !== '' ? $this->building.' — '.$this->unit : $this->building;
    }

    /** "Lease LS-ADR-2024-0031 · Annual" */
    public function leaseLine(): ?string
    {
        $parts = array_filter([
            $this->leaseNumber !== null && $this->leaseNumber !== '' ? 'Lease '.$this->leaseNumber : null,
            $this->cycle,
        ], static fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @return list<string> address and lease line, those present */
    public function lines(): array
    {
        return array_values(array_filter([$this->address, $this->leaseLine()], static fn (?string $line): bool => $line !== null && $line !== ''));
    }
}
