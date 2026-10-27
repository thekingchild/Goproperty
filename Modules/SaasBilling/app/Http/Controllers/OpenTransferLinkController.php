<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Http\Controllers;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\SaasBilling\Transfers\TransferLinks;

/**
 * The link in the billing-transfer email, /billing-transfers/{token}
 * (BILLING_PAYMENTS.md § 7). Signed-in owners of the receiving
 * organization land on the accept screen in their workspace; the sign-in
 * page sends a signed-out visitor back here first. Unknown links, and
 * anyone who does not work in the receiving organization, get the same
 * 404, so the link reveals nothing to someone it was forwarded to. Never
 * opened while GoProperty staff impersonate a user (billing transfers are
 * always blocked then).
 */
#[BlockedWhileImpersonating]
final class OpenTransferLinkController
{
    public function __invoke(Request $request, string $token, TransferLinks $links): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        $transfer = $links->open($token, $user);

        abort_if($transfer === null, 404);

        return redirect()->route('workspace.billing-transfers.show', [
            'organization' => $transfer->toOrganization->slug,
            'transfer' => $transfer->id,
        ]);
    }
}
