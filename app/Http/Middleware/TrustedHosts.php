<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\InstallationMarkers;
use Closure;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/**
 * Only the site's own hosts once it is installed (docs/security/
 * review-2026-11.md SR-01): APP_URL's host and its subdomains, and
 * ADMIN_DOMAIN when the console has its own. Links in emails (password
 * resets, invitations, verification) are built from the request's host, so
 * a forged Host header must never reach them; other names get a plain 400.
 *
 * Not before the install is finalized (the lock file, a file check, no
 * database): a fresh copy has no APP_URL yet, and the web installer is
 * opened at whatever address the owner uses; it checks the origin of its
 * own posts (INSTALLER.md § 3). Laravel skips the check locally and in tests.
 */
final class TrustedHosts extends TrustHosts
{
    /** @return array<int, string> */
    public function hosts(): array
    {
        $console = config('app.admin_domain');
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return array_values(array_filter([
            $this->allSubdomainsOfApplicationUrl(),
            // APP_URL on www.: the bare domain too (a visitor typing it gets
            // the site, or the host's redirect to www, not a 400).
            str_starts_with($host, 'www.') ? '^'.preg_quote(substr($host, 4)).'$' : null,
            is_string($console) && $console !== '' ? '^'.preg_quote(strtolower($console)).'$' : null,
        ]));
    }

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, $next): mixed
    {
        // Symfony keeps trusted hosts in a static: a process that served a
        // checked request (a queue worker, a long test run) must not keep
        // checking requests that should not be.
        if (! $this->shouldSpecifyTrustedHosts()) {
            Request::setTrustedHosts([]);

            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return parent::shouldSpecifyTrustedHosts() && $this->app->make(InstallationMarkers::class)->lockExists();
    }
}
