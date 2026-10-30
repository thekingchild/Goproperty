<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use App\Branding\Branding;
use App\Models\Organization;
use App\Theme\Theme;

/**
 * Who an email comes from and how it looks (PLATFORM_CONSOLE.md § 5;
 * BRANDING_DOCUMENTS.md § 1): for an agency's tenants and staff, the
 * agency's name, colour, logo and contact details, the sender
 * "Adeyemi Realty via GoProperty" (or the agency's own) and replies to the
 * agency's email; for GoProperty's own emails, the platform theme. Plain
 * values, so a queued notification can carry it; the logo is read when the
 * email is built (BrandedMail).
 */
final readonly class EmailBrand
{
    public function __construct(
        public string $name,
        public string $senderName,
        public string $primaryColor,
        public string $productName,
        public ?string $replyTo = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $email = null,
        public bool $showPoweredBy = false,
        public ?int $organizationId = null,
        public ?int $logoMediaId = null,
    ) {}

    public static function forOrganization(Organization $organization, Theme $theme): self
    {
        $branding = Branding::fromOrganization($organization);

        return new self(
            name: $branding->displayName(),
            senderName: $branding->senderName($theme->name()),
            primaryColor: $branding->primaryColor,
            productName: $theme->name(),
            replyTo: $branding->email,
            address: $branding->address,
            phone: $branding->phone,
            email: $branding->email,
            showPoweredBy: ! $branding->hidePoweredBy,
            organizationId: $organization->id,
            logoMediaId: $branding->logoMediaId,
        );
    }

    /** GoProperty's own emails (security, platform billing, product news). */
    public static function platform(Theme $theme): self
    {
        return new self(
            name: $theme->name(),
            senderName: $theme->name(),
            primaryColor: strtoupper($theme->primary()),
            productName: $theme->name(),
        );
    }
}
