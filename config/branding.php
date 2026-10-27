<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Agency branding (BRANDING_DOCUMENTS.md § 1)
|--------------------------------------------------------------------------
|
| Defaults for an organization that has not set its branding yet, the
| store for its logo, signature and stamp, and which plans may remove the
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

    // Logo, signature and stamp images: a private disk, served only through
    // the workspace's own route (App\Http\Controllers\Workspace\BrandingFileController).
    // The media library (P02-05) takes them over.
    'disk' => env('BRANDING_DISK', 'local'),
    'folder' => 'organization-branding',

    // Plans whose organizations may hide the "Generated with …" line (§ 1, Plan differences).
    'remove_footer_plans' => ['scale', 'enterprise'],

];
