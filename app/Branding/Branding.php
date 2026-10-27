<?php

declare(strict_types=1);

namespace App\Branding;

use App\Documents\DocumentLayout;
use App\Models\Organization;
use App\Theme\Color;
use Illuminate\Support\Str;

/**
 * An organization's branding (BRANDING_DOCUMENTS.md § 1), as stored in
 * `organizations.branding` with defaults filled in. The company name is the
 * organization's name (Settings → Profile; Tier 2/3 must match the CAC
 * name), everything else is in the json. Images are media ids
 * (App\Branding\BrandingFiles, the media library's `branding` collection);
 * the logo's is the `organizations.logo_media_id` column, which
 * BrandingSettings keeps equal to the json's `logo_media_id`.
 *
 * Issued documents keep a copy of toArray() as their branding snapshot
 * (App\Documents\BrandingSnapshot, with the images themselves), so later
 * changes never alter them.
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
        public ?int $logoMediaId,
        public ?int $signatureMediaId,
        public ?int $stampMediaId,
    ) {}

    public static function fromOrganization(Organization $organization): self
    {
        $stored = $organization->branding ?? [];
        $text = static fn (string $key): ?string => isset($stored[$key]) && is_string($stored[$key]) && trim($stored[$key]) !== '' ? $stored[$key] : null;
        $colour = static fn (string $key, ?string $default): ?string => ($value = $text($key)) !== null && Color::isHex($value) ? strtoupper(Color::normalize($value)) : $default;
        $id = static fn (string $key): ?int => isset($stored[$key]) && is_int($stored[$key]) && $stored[$key] > 0 ? $stored[$key] : null;
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
            logoMediaId: $organization->logo_media_id ?? $id('logo_media_id'),
            signatureMediaId: $id('signature_media_id'),
            stampMediaId: $id('stamp_media_id'),
        );
    }

    /**
     * Rebuilds the branding a document was issued with from its snapshot
     * (App\Documents\BrandingSnapshot): toArray() plus `company_name`. Read
     * as stored, with no defaults from today's settings, so an issued
     * document never changes.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function fromSnapshot(array $values): self
    {
        $text = static fn (string $key): ?string => isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '' ? $values[$key] : null;
        $id = static fn (string $key): ?int => isset($values[$key]) && is_int($values[$key]) ? $values[$key] : null;

        return new self(
            companyName: $text('company_name') ?? '',
            tradingName: $text('trading_name'),
            primaryColor: ($primary = $text('primary_color')) !== null && Color::isHex($primary) ? $primary : self::defaultPrimary(),
            accentColor: ($accent = $text('accent_color')) !== null && Color::isHex($accent) ? $accent : null,
            address: $text('address'),
            rcNumber: $text('rc_number'),
            phone: $text('phone'),
            email: $text('email'),
            website: $text('website'),
            invoicePrefix: $text('invoice_prefix') ?? '',
            receiptPrefix: $text('receipt_prefix') ?? '',
            leasePrefix: $text('lease_prefix') ?? '',
            invoiceNotes: $text('invoice_notes'),
            receiptFooter: $text('receipt_footer'),
            layout: DocumentLayout::tryFrom((string) $text('layout')) ?? DocumentLayout::Modern,
            emailSenderName: $text('email_sender_name'),
            hidePoweredBy: ($values['hide_powered_by'] ?? false) === true,
            logoMediaId: $id('logo_media_id'),
            signatureMediaId: $id('signature_media_id'),
            stampMediaId: $id('stamp_media_id'),
        );
    }

    /**
     * The json kept in `organizations.branding`.
     *
     * @return array<string, string|int|bool|null>
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
            'logo_media_id' => $this->logoMediaId,
            'signature_media_id' => $this->signatureMediaId,
            'stamp_media_id' => $this->stampMediaId,
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

    /** The media id of the logo, signature or stamp image. */
    public function mediaId(string $role): ?int
    {
        return match ($role) {
            'logo' => $this->logoMediaId,
            'signature' => $this->signatureMediaId,
            'stamp' => $this->stampMediaId,
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
