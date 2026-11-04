<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's password confirmation and password reset forms have no rate
 * limit of their own (docs/security/review-2026-11.md SR-06): someone
 * holding a signed-in browser could guess the password at "Confirm your
 * password" as fast as they like, and it guards two-factor and passkey
 * changes. Sits in Fortify's middleware (config/fortify.php) and applies
 * the named limiters of FortifyServiceProvider to those two POSTs only.
 */
final readonly class ThrottlePasswordChecks
{
    /** Route name => limiter name. */
    public const array ROUTES = [
        'password.confirm.store' => 'password-confirm',
        'password.update' => 'password-reset',
    ];

    public function __construct(private ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::ROUTES[(string) $request->route()?->getName()] ?? null;

        if ($limiter === null) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, $limiter);
    }
}
