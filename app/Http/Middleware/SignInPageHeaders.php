<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SignIn\Turnstile;
use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy for the sign-in pages (UX_DESIGN.md § 3.1): no
 * inline scripts and no third-party scripts except Cloudflare Turnstile.
 * Applied to pages rendered with the split sign-in layout, which marks the
 * request (Fortify registers the routes, so they are not ours to tag).
 */
final readonly class SignInPageHeaders
{
    public const string ATTRIBUTE = 'sign-in-page';

    public function __construct(private Vite $vite) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->attributes->getBoolean(self::ATTRIBUTE)) {
            return $response;
        }

        $dev = [];

        if ($this->vite->isRunningHot()) {
            $hot = rtrim((string) file_get_contents($this->vite->hotFile()));
            $dev = [$hot, (string) preg_replace('/^http/', 'ws', $hot)];
        }

        $sources = fn (array $extra): string => implode(' ', array_unique(array_merge(["'self'"], $extra)));

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            'script-src '.$sources([Turnstile::SCRIPT_HOST, ...$dev]),
            'style-src '.$sources([...$dev, "'unsafe-inline'"]),
            'img-src '.$sources(['data:']),
            'font-src '.$sources(['data:', ...$dev]),
            'connect-src '.$sources($dev),
            'frame-src '.Turnstile::SCRIPT_HOST,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
