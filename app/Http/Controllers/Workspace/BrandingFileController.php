<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Branding\BrandingFiles;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * /o/{organization}/settings/branding/files/{role}/{file}: an organization's
 * logo, signature or stamp image (BrandingFiles), for its own workspace
 * pages and the branding preview. Inside the `organization` middleware, so
 * only members who open the workspace get here, and only for their own
 * organization's files (the path is built from the organization in the
 * URL). The logo is for any of them; the signature and stamp, which could
 * be misused on a fake document, only for those who see the branding
 * settings. The files were re-encoded as PNG when stored. Documents issued
 * as PDFs embed the images instead of linking here.
 */
final class BrandingFileController
{
    public function __invoke(Request $request, Organization $organization, string $role, string $file, BrandingFiles $files): Response
    {
        if ($role !== 'logo') {
            abort_unless(Gate::allows('viewSettings', $organization), 404);
        }

        $path = $files->locate($organization, $role, $file);
        $bytes = $path !== null ? $files->read($organization, $path) : null;
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
            // Named by content hash: the same URL never changes.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
