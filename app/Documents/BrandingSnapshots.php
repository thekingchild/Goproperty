<?php

declare(strict_types=1);

namespace App\Documents;

use App\Branding\Branding;
use App\Branding\BrandingFiles;
use App\Models\Organization;
use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;

/**
 * Takes the branding snapshot an issued document keeps (BrandingSnapshot):
 * the organization's saved branding now, with its images read from the
 * media library and embedded.
 */
final readonly class BrandingSnapshots
{
    public function __construct(
        private BrandingFiles $files,
        private ThemeContext $theme,
    ) {}

    public function take(Organization $organization): BrandingSnapshot
    {
        $branding = Branding::fromOrganization($organization);
        $images = [];

        foreach (Branding::IMAGE_ROLES as $role) {
            $images[$role] = $this->files->dataUri($organization, $role, $branding->mediaId($role));
        }

        return new BrandingSnapshot($branding, $this->theme->current()->name(), $images, CarbonImmutable::now());
    }
}
