<?php

declare(strict_types=1);

namespace Modules\Tenants\Http\Controllers;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Tenants\Portal\PortalRentals;

/**
 * A bill's invoice as a web page from the tenant portal,
 * /portal/bills/{bill}/invoice (P03-11a; BRANDING_DOCUMENTS.md § 5: every
 * document is also a responsive web page): the same invoice members open
 * (P03-05c), with the branding and parties as issued and the status and
 * payments now. Only the user's own bill (TenantProfilePolicy::
 * viewInvoiceAsTenant); anyone else's answers 404. The printed invoice
 * carries the tenant's phone and email, so it is not opened while GoProperty
 * staff impersonate (PLATFORM_CONSOLE.md § 6, as BillPolicy::viewInvoice).
 * The PDF from the portal is P03-11b's.
 */
final class PortalInvoiceController
{
    #[BlockedWhileImpersonating]
    public function show(Request $request, int $bill, PortalRentals $rentals): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $html = $rentals->invoice($user, $bill);
        abort_if($html === null, 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
