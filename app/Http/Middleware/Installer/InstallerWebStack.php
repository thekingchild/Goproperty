<?php

declare(strict_types=1);

namespace App\Http\Middleware\Installer;

use App\Installer\ApplicationKey;
use App\Installer\Web\InstallerSession;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web stack for the wizard's steps (INSTALLER.md § 4): cookie
 * encryption, the installer's own encrypted file session, CSRF and the
 * grant. Without APP_KEY there is no session to resume, so the request goes
 * back to the unlock screen before any of that is even built: listed as
 * route middleware, the cookie and CSRF middleware would be constructed
 * (and fail on the missing key) when the request ends.
 */
final class InstallerWebStack
{
    /** @var list<class-string> */
    public const array STACK = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        PreventRequestForgery::class,
        RequireInstallerGrant::class,
    ];

    public function __construct(
        private readonly Container $app,
        private readonly InstallerSession $session,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApplicationKey::configured()) {
            return new RedirectResponse($request->getBaseUrl().'/install', 303, ['Cache-Control' => 'no-store']);
        }

        $this->session->configure($request);

        return (new Pipeline($this->app))->send($request)->through(self::STACK)->then($next);
    }
}
