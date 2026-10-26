<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Marketing\PublicSite;
use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy for the public marketing pages
 * (PLATFORM_CONSOLE.md § 9.5): scripts load from this site and the hosts in
 * custom_code.allowed_hosts only, so a pasted script from an unknown host is
 * blocked until a super-admin adds the host. Inline scripts stay allowed
 * because analytics snippets are inline.
 */
final readonly class PublicPageHeaders
{
    public function __construct(
        private PublicSite $site,
        private Vite $vite,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $hosts = array_map(fn (string $host): string => 'https://'.$host, $this->site->allowedScriptHosts());
        $dev = [];

        if ($this->vite->isRunningHot()) {
            $hot = rtrim((string) file_get_contents($this->vite->hotFile()));
            $dev = [$hot, (string) preg_replace('/^http/', 'ws', $hot)];
        }

        $sources = fn (array $extra): string => implode(' ', array_unique(array_merge(["'self'"], $extra)));

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            'script-src '.$sources([...$hosts, ...$dev, "'unsafe-inline'"]),
            'style-src '.$sources([...$dev, "'unsafe-inline'"]),
            'img-src '.$sources(['data:', 'https:']),
            'font-src '.$sources(['data:', ...$dev]),
            'connect-src '.$sources([...$hosts, ...$dev]),
            'frame-src '.$sources($hosts),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
