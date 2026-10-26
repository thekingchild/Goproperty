<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\ConsoleRequests;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runs before any session starts. Console requests (and Livewire updates
 * from console pages) use the staff session cookie `gp_staff_session`
 * (Secure, HttpOnly, SameSite=Strict, 30-minute idle timeout), so staff and
 * customer sessions never mix on one domain (ONBOARDING_COMPLIANCE.md § 1).
 * The optional IP allowlist answers other addresses with a plain 404.
 */
final class UseConsoleSession
{
    public function __construct(
        private readonly ConsoleRequests $console,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->console->isConsoleRequest($request)) {
            return $next($request);
        }

        if (! $this->console->ipAllowed($request)) {
            throw new NotFoundHttpException;
        }

        $this->config->set([
            'session.cookie' => $this->config->get('console.session.cookie', 'gp_staff_session'),
            'session.lifetime' => $this->config->get('console.session.lifetime', 30),
            'session.expire_on_close' => false,
            'session.path' => '/',
            'session.domain' => null,
            'session.http_only' => true,
            'session.same_site' => 'strict',
            // Secure everywhere except a local copy served over plain http.
            'session.secure' => $request->isSecure() || ! app()->isLocal(),
        ]);

        return $next($request);
    }
}
