<?php

declare(strict_types=1);

namespace App\Documents;

use App\Branding\BrandColours;
use App\Branding\Branding;

/**
 * Everything a document template needs from an organization's branding
 * (BRANDING_DOCUMENTS.md § 1–5), worked out once: the colours with their
 * readable text shade and soft tint, the letterhead lines, image sources
 * (a workspace URL on web pages and the live preview, a data URI in PDFs)
 * and the footer line. The Blade templates in resources/views/documents
 * take one of these and the document's data, and print only hex colours
 * and table layouts, so dompdf and Chrome render them alike (§ 5).
 */
final readonly class DocumentBranding
{
    public function __construct(
        public Branding $branding,
        public string $productName,
        public bool $showPoweredBy,
        public ?string $logoSrc = null,
        public ?string $signatureSrc = null,
        public ?string $stampSrc = null,
    ) {}

    public function layout(): DocumentLayout
    {
        return $this->branding->layout;
    }

    public function name(): string
    {
        return $this->branding->displayName();
    }

    /** The registered name, when a trading name is shown above it. */
    public function legalName(): ?string
    {
        return $this->branding->tradingName !== null && $this->branding->tradingName !== $this->branding->companyName ? $this->branding->companyName : null;
    }

    /** The brand bar and other areas with no text on them. */
    public function primary(): string
    {
        return strtolower($this->branding->primaryColor);
    }

    /** Text in the brand colour, and the ground under white text: 4.5:1 against white. */
    public function strong(): string
    {
        return BrandColours::textShade($this->branding->primaryColor);
    }

    /** Soft panels (party cards, the amount panel on receipts). */
    public function tint(): string
    {
        return BrandColours::tint($this->branding->primaryColor, 0.06);
    }

    /** Small labels on the strong colour. */
    public function onStrongMuted(): string
    {
        return BrandColours::mix('#ffffff', $this->strong(), 0.78);
    }

    public function accent(): string
    {
        return strtolower($this->branding->accentColor ?? BrandColours::tint($this->branding->primaryColor, 0.45));
    }

    public function onAccent(): string
    {
        return BrandColours::readableOn($this->accent());
    }

    public function initials(): string
    {
        return $this->branding->initials();
    }

    /** @return list<string> address, RC number and phone, email and website */
    public function letterheadLines(): array
    {
        $b = $this->branding;

        return array_values(array_filter([
            $b->address,
            implode(' · ', array_filter([$b->rcNumber, $b->phone])),
            implode(' · ', array_filter([$b->email, $b->website])),
        ], static fn (?string $line): bool => $line !== null && $line !== ''));
    }

    /** "INV-ADR-2027-000142" for a sequence number (ARCHITECTURE.md § 6.5). */
    public function number(string $prefix, int $year, int $sequence): string
    {
        return $prefix.'-'.$year.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
