<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\SettingsStore;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Keep me signed in" lasts auth.remember_days (PLATFORM_CONSOLE.md § 9.6),
 * not Laravel's default, on every sign-in route (password, 2FA, passkey).
 */
final readonly class RememberDuration
{
    public function __construct(
        private Auth $auth,
        private SettingsStore $settings,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('web');
        $days = $this->settings->get('auth.remember_days');

        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration((is_int($days) ? $days : 30) * 24 * 60);
        }

        return $next($request);
    }
}
