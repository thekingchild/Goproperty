<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\ApplicationKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * First in the global stack (INSTALLER.md § 3). Until APP_KEY exists, every
 * GET goes to /install and every write gets a plain 503. It uses no session,
 * cookie, database or log, so a freshly uploaded copy never fills the error
 * log with "No application encryption key" errors.
 */
final class AwaitApplicationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApplicationKey::configured() || $request->is('install', 'install/*')) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return new RedirectResponse($request->getBaseUrl().'/install', 302, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response("This site is being set up. Please try again later.\n", 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Retry-After' => '300',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
