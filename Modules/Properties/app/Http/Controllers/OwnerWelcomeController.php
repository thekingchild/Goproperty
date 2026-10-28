<?php

declare(strict_types=1);

namespace Modules\Properties\Http\Controllers;

use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /owner/welcome: shown once an owner has created their account from an
 * invitation (P03-15). Offers a passkey (optional, UX_DESIGN.md § 3.3;
 * added under Settings → Security) and the owner portal. Only for
 * signed-in users; it changes nothing.
 */
final class OwnerWelcomeController
{
    public function show(Request $request, SettingsStore $settings): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('properties::owner-portal.welcome', [
            'user' => $user,
            'passkeys' => $settings->get('auth.passkeys_enabled') !== false,
        ]);
    }
}
