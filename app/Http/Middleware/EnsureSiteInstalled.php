<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\InstallationMarkers;
use App\Installer\SiteState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the install is finalized, every public route (landing page, login,
 * signup, /o/…, /portal, webhooks) shows a neutral "being set up" page, so
 * nobody can register or sign in mid-install (INSTALLER.md § 3). The
 * installer itself and the /up health check stay reachable.
 */
final class EnsureSiteInstalled
{
    public function __construct(private readonly InstallationMarkers $markers) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('install', 'install/*', 'up')) {
            return $next($request);
        }

        return match ($this->markers->siteState()) {
            SiteState::Open => $next($request),
            SiteState::NotInstalled => self::page($request, 'installer.setting-up'),
            SiteState::Unavailable => self::page($request, 'installer.unavailable'),
        };
    }

    public static function page(Request $request, string $view, int $status = 503): Response
    {
        return response()->view($view, [
            'name' => config('app.name'),
            'stylesheet' => $request->getBasePath().'/install.css',
            'local' => app()->isLocal(),
        ], $status, [
            'Retry-After' => '300',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
