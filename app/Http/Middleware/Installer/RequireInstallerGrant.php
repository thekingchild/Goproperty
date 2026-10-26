<?php

declare(strict_types=1);

namespace App\Http\Middleware\Installer;

use App\Installer\Web\GrantStatus;
use App\Installer\Web\InstallerGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every wizard step needs the installer grant (INSTALLER.md § 4). Without
 * it, or once it expired, the owner unlocks again on the first screen; no
 * shell is needed.
 */
final class RequireInstallerGrant
{
    public function __construct(private readonly InstallerGrant $grant) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->grant->check($request->session());

        if ($status !== GrantStatus::Valid) {
            return new RedirectResponse($request->getBaseUrl().'/install?session='.$status->value, 303, ['Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
