<?php

declare(strict_types=1);

namespace Modules\Properties\Http\Controllers;

use App\Media\MediaUrls;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Properties\OwnerPortal\OwnerPortalRecords;

/**
 * /owner/leases/{lease}/contract (P03-16; product owner 2026-10-29): the
 * issued contract of a lease of the owner's units, as kept (Leasing's
 * OwnerLeaseContracts: rendered once, never re-rendered, never issued
 * from here; drafts never). Found through OwnerPortalRecords (the "linked
 * to my user" path, each owner profile checked by
 * OwnerProfilePolicy::viewAsOwner), so another owner's or organization's
 * lease, or one with no contract on file there, answers 404. Sent as a
 * short-lived signed link (App\Media\MediaUrls, ARCHITECTURE.md § 9). It
 * prints the tenant's phone and email, so, like the members' and the
 * tenant portal's contract downloads, it does not open while GoProperty
 * staff impersonate (PLATFORM_CONSOLE.md § 6). Throttled per user (routes).
 */
final class OwnerContractController
{
    #[BlockedWhileImpersonating]
    public function __invoke(Request $request, int $lease, OwnerPortalRecords $records, MediaUrls $urls): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $media = $records->contractPdf($user, $lease);
        abort_if($media === null, 404);

        return redirect()->away($urls->temporary($media))->header('Cache-Control', 'no-store, private');
    }
}
