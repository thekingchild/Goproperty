<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Branding\BrandingFiles;
use App\Models\Organization;

/**
 * The managing agency's header in the owner portal's statement pages
 * (P08-05; UX_DESIGN.md § 2: each agency in its own colours, as the owner
 * portal's other pages show it): its current name, colour, initials and
 * logo. Inside its organization.
 */
final readonly class PortalAgency
{
    public function __construct(
        public int $organizationId,
        public string $name,
        public string $colour,
        public string $initials,
        public ?string $logoSrc,
    ) {}

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
