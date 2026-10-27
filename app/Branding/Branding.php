<?php

declare(strict_types=1);

namespace App\Branding;

use App\Enums\DocumentLayout;
use App\Models\Organization;
use App\Theme\Color;
use Illuminate\Support\Str;

/**
 * An organization's branding (BRANDING_DOCUMENTS.md § 1), as stored in
 * `organizations.branding` with defaults filled in. The company name is the
 * organization's name (Settings → Profile; Tier 2/3 must match the CAC
 * name), everything else is in the json. Image fields hold paths on the
 * branding disk (App\Branding\BrandingFiles) until the media library
 * (P02-05) replaces them with media ids.
 *
 * Issued documents keep a copy of toArray() as their branding snapshot
 * (P03-05), so later changes never alter them.
 */
final readonly class Branding
{
    public const array IMAGE_ROLES = ['logo', 'signature', 'stamp'];

    public function __construct(
        public string $companyName,
        public ?string $tradingName,
        public string $primaryColor,
        public ?string $accentColor,
        public ?string $address,
        public ?string $rcNumber,
        public ?string $phone,
        public ?string $email,
        public ?string $website,
        public string $invoicePrefix,
        public string $receiptPrefix,
        public string $leasePrefix,
        public ?string $invoiceNotes,
        public ?string $receiptFooter,
        public DocumentLayout $layout,
        public ?string $emailSenderName,
        public bool $hidePoweredBy,
        public ?string $logoFile,
        public ?string $signatureFile,
        public ?string $stampFile,
    ) {}

    public static function fromOrganization(Organization $organization): self
    {
        $stored = $organization->branding ?? [];
        $text = static fn (string $key): ?string => isset($stored[$key]) && is_string($stored[$key]) && trim($stored[$key]) !== '' ? $stored[$key] : null;
        $colour = static fn (string $key, ?string $default): ?string => ($value = $text($key)) !== null && Color::isHex($value) ? strtoupper(Color::normalize($value)) : $default;
        $code = self::defaultCode($organization->name);

        return new self(
            companyName: $organization->name,
            tradingName: $text('trading_name'),
            primaryColor: (string) $colour('primary_color', self::defaultPrimary()),
            accentColor: array_key_exists('accent_color', $stored) ? $colour('accent_color', null) : self::defaultAccent(),
            address: $text('address') ?? $organization->address,
            rcNumber: $text('rc_number') ?? $organization->cac_number,
            phone: $text('phone'),
            email: $text('email'),
            website: $text('website'),
            invoicePrefix: $text('invoice_prefix') ?? 'INV-'.$code,
            receiptPrefix: $text('receipt_prefix') ?? 'RCT-'.$code,
            leasePrefix: $text('lease_prefix') ?? 'LS-'.$code,
            invoiceNotes: $text('invoice_notes'),
            receiptFooter: $text('receipt_footer'),
            layout: DocumentLayout::tryFrom((string) $text('layout')) ?? DocumentLayout::Modern,
            emailSenderName: $text('email_sender_name'),
            hidePoweredBy: ($stored['hide_powered_by'] ?? false) === true,
            logoFile: $text('logo_file'),
            signatureFile: $text('signature_file'),
            stampFile: $text('stamp_file'),
        );
    }

    /**
     * The json kept in `organizations.branding`.
     *
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'trading_name' => $this->tradingName,
            'primary_color' => $this->primaryColor,
            'accent_color' => $this->accentColor,
            'address' => $this->address,
            'rc_number' => $this->rcNumber,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'invoice_prefix' => $this->invoicePrefix,
            'receipt_prefix' => $this->receiptPrefix,
            'lease_prefix' => $this->leasePrefix,
            'invoice_notes' => $this->invoiceNotes,
            'receipt_footer' => $this->receiptFooter,
            'layout' => $this->layout->value,
            'email_sender_name' => $this->emailSenderName,
            'hide_powered_by' => $this->hidePoweredBy,
            'logo_file' => $this->logoFile,
            'signature_file' => $this->signatureFile,
            'stamp_file' => $this->stampFile,
        ];
    }

    /** The name on headers and in the email sender: the trading name when there is one. */
    public function displayName(): string
    {
        return $this->tradingName ?? $this->companyName;
    }

    /** "Adeyemi Realty via GoProperty" unless the organization chose its own. */
    public function senderName(string $productName): string
    {
        return $this->emailSenderName ?? $this->displayName().' via '.$productName;
    }

    public function file(string $role): ?string
    {
        return match ($role) {
            'logo' => $this->logoFile,
            'signature' => $this->signatureFile,
            'stamp' => $this->stampFile,
            default => null,
        };
    }

    /** Up to two letters for the monogram shown when there is no logo. */
    public function initials(): string
    {
        $words = array_values(array_filter(preg_split('/[^\pL\pN]+/u', self::withoutSuffixes($this->displayName())) ?: []));

        $initials = match (count($words)) {
            0 => 'GP',
            1 => mb_substr($words[0], 0, 2),
            default => mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1),
        };

        return mb_strtoupper($initials);
    }

    public static function defaultPrimary(): string
    {
        $value = config('branding.default_primary', '#0E6B5C');

        return is_string($value) && Color::isHex($value) ? strtoupper(Color::normalize($value)) : '#0E6B5C';
    }

    public static function defaultAccent(): ?string
    {
        $value = config('branding.default_accent', '#F2B53A');

        return is_string($value) && Color::isHex($value) ? strtoupper(Color::normalize($value)) : null;
    }

    /**
     * The organization part of document numbers, from the name: "Adeyemi
     * Realty Ltd" → ADR (INV-ADR-2027-000142, BRANDING_DOCUMENTS.md § 1).
     */
    public static function defaultCode(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[^A-Z0-9]+/', strtoupper(Str::ascii(self::withoutSuffixes($name)))) ?: []));

        $code = match (count($words)) {
            0 => 'ORG',
            1 => substr($words[0], 0, 3),
            default => substr($words[0], 0, 2).substr($words[1], 0, 1),
        };

        return strlen($code) >= 2 ? $code : str_pad($code, 2, 'X');
    }

    private static function withoutSuffixes(string $name): string
    {
        return (string) preg_replace('/\b(ltd|limited|plc|nig|nigeria|enterprises?|ventures?)\b\.?/i', ' ', $name);
    }
}
