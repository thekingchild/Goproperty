<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Branding\BrandingFiles;
use App\Models\Organization;

/**
 * An organization that manages buildings of the owner, as the owner portal
 * frames it (P03-15; UX_DESIGN.md § 2: each agency in its own colours):
 * its current name, colour, initials and logo (a data URI, read inside the
 * organization). Plain values, safe to render outside the organization.
 */
final readonly class OwnerAgency
{
    public function __construct(
        public int $organizationId,
        public string $name,
        public string $colour,
        public string $initials,
        public ?string $logoSrc,
    ) {}

    /** The organization's branding now. Inside its organization. */
    public static function of(Organization $organization): self
    {
        $branding = Branding::fromOrganization($organization);

        return new self(
            organizationId: (int) $organization->id,
            name: $branding->displayName(),
            colour: BrandColours::textShade($branding->primaryColor),
            initials: $branding->initials(),
            logoSrc: app(BrandingFiles::class)->dataUri($organization, 'logo', $branding->mediaId('logo')),
        );
    }
}
