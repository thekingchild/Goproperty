<?php

declare(strict_types=1);

namespace Modules\Tenants\Http\Controllers;

use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /portal/welcome: shown once a tenant has created their account from an
 * invitation (P03-01b). Offers a passkey (optional, UX_DESIGN.md § 3.3;
 * added under Settings → Security) and the portal. Only for signed-in
 * users; it changes nothing.
 */
final class PortalWelcomeController
{
    public function __invoke(Request $request, SettingsStore $settings): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('tenants::portal.welcome', [
            'user' => $user,
            'passkeys' => $settings->get('auth.passkeys_enabled') !== false,
        ]);
    }
}
