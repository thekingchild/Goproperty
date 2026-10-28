<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\UtilityReading;

/**
 * A meter reading's photo (P03-06a), for the members who may see the
 * reading (UtilityReadingPolicy::viewPhoto: narrowed like the reading): a
 * redirect to a short-lived signed link (App\Media\MediaUrls;
 * ARCHITECTURE.md § 9), made only after the policy check. The photo is
 * evidence (`meter_photos`) and stays with the reading, removed or not.
 *
 * {reading} resolves inside the organization only (the `organization`
 * middleware runs first), so another organization's reading answers 404.
 */
final class MeterPhotoController
{
    public function __invoke(Organization $organization, UtilityReading $reading, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewPhoto', $reading);

        $media = $reading->getFirstMedia(UtilityReading::PHOTO_COLLECTION);
        abort_unless($media instanceof Media, 404);

        return redirect()->away($urls->temporary($media));
    }
}
