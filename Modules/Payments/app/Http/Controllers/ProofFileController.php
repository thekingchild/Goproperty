<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Payments\Models\PaymentProof;

/**
 * A proof of payment's file (P03-09), for the members who may see it
 * (PaymentProofPolicy::viewFile: never while staff impersonate): a redirect
 * to a short-lived signed link (App\Media\MediaUrls; ARCHITECTURE.md § 9),
 * made only after the policy check. thumb() gives the image's thumbnail
 * once the `media` queue made it, else the original; a PDF has none.
 * Archived proofs keep their file (evidence).
 *
 * {proof} resolves inside the organization only (the `organization`
 * middleware runs first), so another organization's proof answers 404.
 */
final class ProofFileController
{
    public function show(Organization $organization, PaymentProof $proof, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewFile', $proof);

        return redirect()->away($urls->temporary($this->media($proof)));
    }

    public function thumb(Organization $organization, PaymentProof $proof, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewFile', $proof);
        $media = $this->media($proof);
        abort_unless(str_starts_with((string) $media->mime_type, 'image/'), 404);

        return redirect()->away($urls->preview($media, 'thumb'));
    }

    private function media(PaymentProof $proof): Media
    {
        $media = $proof->media_id !== null ? Media::query()->find($proof->media_id) : null;
        abort_unless($media instanceof Media, 404);

        return $media;
    }
}
