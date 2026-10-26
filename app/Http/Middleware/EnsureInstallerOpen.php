<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Installer\InstallationMarkers;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /install and /install/* answer 404, like any unknown page, once any
 * installation marker says the install finished (INSTALLER.md § 3).
 */
final class EnsureInstallerOpen
{
    public function __construct(private readonly InstallationMarkers $markers) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->markers->installerOpen()) {
            throw new NotFoundHttpException;
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
