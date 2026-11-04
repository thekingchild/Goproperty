<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every response gets (ARCHITECTURE.md § 10 "HTTPS enforced HSTS";
 * UX_DESIGN.md § 3.1; docs/security/review-2026-11.md SR-04), unless the
 * route set its own (the sign-in, public, media and download routes set
 * stricter ones):
 *
 * - `Strict-Transport-Security` (one year) whenever the site's address
 *   (APP_URL) is https, whatever this request came in on: behind a proxy
 *   or a host's SSL front the request may look like http, and browsers
 *   ignore the header on plain http anyway. Not on local copies.
 * - `X-Frame-Options: SAMEORIGIN`: the workspace, portals and console are
 *   never framed by another site (clickjacking); their own previews still
 *   work.
 * - `X-Content-Type-Options: nosniff` and
 *   `Referrer-Policy: strict-origin-when-cross-origin` (no paths, tokens or
 *   ids in Referer headers to other sites).
 *
 * The HTTP-to-HTTPS redirect itself is the host's (docs/runbooks/
 * shared-hosting.md § 2, step 6): the app cannot tell a proxy's https from
 * plain http without trusting proxies, so it does not redirect.
 */
final readonly class SecurityHeaders
{
    public const string HSTS = 'max-age=31536000';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (str_starts_with((string) config('app.url'), 'https://') && ! app()->isLocal() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }

        foreach ([
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ] as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }
}
