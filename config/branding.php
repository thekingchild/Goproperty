<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Agency branding (BRANDING_DOCUMENTS.md § 1)
|--------------------------------------------------------------------------
|
| Defaults for an organization that has not set its branding yet, where
| P01-05 kept its logo, signature and stamp, and which plans may remove the
| "Generated with …" footer line. The values an organization chooses live
| in `organizations.branding` (App\Branding\Branding).
|
*/

return [

    // The colours of the approved invoice and receipt designs
    // (docs/design/templates/), not the platform theme: agency documents
    // carry agency branding, never GoProperty's.
    'default_primary' => '#0E6B5C',
    'default_accent' => '#F2B53A',

    // Where P01-05 stored logo, signature and stamp images. Since P02-05 they
    // are in the media library (App\Branding\BrandingFiles, collection
    // `branding`); the 2026_11_02_050002 migration read the old files from
    // here, and they stay here untouched.
    'disk' => env('BRANDING_DISK', 'local'),
    'folder' => 'organization-branding',

    // Plans whose organizations may hide the "Generated with …" line (§ 1, Plan differences).
    'remove_footer_plans' => ['scale', 'enterprise'],

];
