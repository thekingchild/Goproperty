<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\SettingsStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth.passkeys_enabled (PLATFORM_CONSOLE.md § 9.6): when off, passkey
 * sign-in is refused on the server, not just hidden on the page.
 * Managing passkeys in the profile stays available.
 */
final readonly class PasskeySignInSwitch
{
    public function __construct(private SettingsStore $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passkey.login', 'passkey.login-options') && $this->settings->get('auth.passkeys_enabled') === false) {
            abort(404);
        }

        return $next($request);
    }
}
