<?php

declare(strict_types=1);

namespace Modules\Handovers\Http\Controllers;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Handovers\Handovers\HandoverLinks;

/**
 * The link in the handover email, /handovers/{token} (BILLING_PAYMENTS.md
 * § 7). Signed-in owners of the receiving organization land on the accept
 * screen in their workspace; the sign-in page sends a signed-out visitor
 * back here first. Unknown links, and anyone who does not work in the
 * receiving organization, get the same 404, so the link reveals nothing to
 * someone it was forwarded to. Never opened while GoProperty staff
 * impersonate a user (handovers are always blocked then).
 */
#[BlockedWhileImpersonating]
final class OpenHandoverLinkController
{
    public function __invoke(Request $request, string $token, HandoverLinks $links): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        $handover = $links->open($token, $user);

        abort_if($handover === null, 404);

        return redirect()->route('workspace.handovers.show', [
            'organization' => $handover->toOrganization->slug,
            'handover' => $handover->id,
        ]);
    }
}
