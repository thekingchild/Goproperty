<?php

declare(strict_types=1);

use App\Http\Middleware\AwaitApplicationKey;
use App\Http\Middleware\EndSessionsAfterPasswordChange;
use App\Http\Middleware\EnsurePlanAllows;
use App\Http\Middleware\EnsureSiteInstalled;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\ResumeImpersonation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetOrganizationContext;
use App\Http\Middleware\StaffAccountState;
use App\Http\Middleware\TrustedHosts;
use App\Http\Middleware\UseConsoleSession;
use App\Http\Middleware\WatchSchedulerHeartbeat;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelPreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], __DIR__.'/../routes/install.php');
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    // POST /broadcasting/auth for private channels (Reverb on a VPS), for
    // signed-in, verified users like the pages it serves; the modules
    // register their channels (routes/channels.php).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'verified', 'throttle:120,1']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Before anything else: no APP_KEY → /install, then "being set up"
        // until the install is finalized (INSTALLER.md § 3).
        $middleware->prepend([
            // Only the site's own hosts once installed (SR-01, TrustedHosts).
            TrustedHosts::class,
            AwaitApplicationKey::class,
            EnsureSiteInstalled::class,
            // Staff session cookie for console requests, before any session
            // starts (ONBOARDING_COMPLIANCE.md § 1).
            UseConsoleSession::class,
        ]);

        // Maintenance mode for an upgrade keeps the console open for the
        // upgrade step on hosts without SSH (INSTALLER.md § 10, P09-06).
        $middleware->replace(LaravelPreventRequestsDuringMaintenance::class, PreventRequestsDuringMaintenance::class);

        // After each response: is the scheduler still running? A stopped
        // scheduler cannot raise its own alert (PLATFORM_CONSOLE.md § 2, P08-03).
        $middleware->append(WatchSchedulerHeartbeat::class);

        // HSTS, framing, nosniff and referrer on every response that did not
        // set its own (ARCHITECTURE.md § 10).
        $middleware->append(SecurityHeaders::class);

        // Paystack's server-to-server calls (OnlinePayments, P03-08): the
        // route is outside the `web` group (no session), and never asks
        // for a CSRF token; the HMAC signature proves the caller.
        // The email provider's bounce and complaint calls (P05-04) likewise:
        // the secret token in the URL proves the caller.
        $middleware->validateCsrfTokens(except: ['webhooks/paystack', 'webhooks/paystack/*', 'webhooks/email/*', 'webhooks/whatsapp/*']);

        // /o/{organization} routes: the context must exist before route
        // model binding resolves organization-owned models (ARCHITECTURE.md § 4).
        $middleware->alias(['organization' => SetOrganizationContext::class, 'plan' => EnsurePlanAllows::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetOrganizationContext::class);

        // A deactivated staff member's session ends before the console's
        // Authenticate middleware answers 403 (PLATFORM_CONSOLE.md § 1).
        $middleware->prependToPriorityList(AuthenticatesRequests::class, StaffAccountState::class);

        // GoProperty support sessions on the customer side: after the
        // session starts, before `auth` (PLATFORM_CONSOLE.md § 6).
        $middleware->web(append: [ResumeImpersonation::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResumeImpersonation::class);

        // A changed or reset password ends the account's other sessions and
        // "remember me" cookies at their next request (UX_DESIGN.md § 3.1;
        // docs/security/review-2026-11.md SR-07); the session that made the
        // change keeps going.
        $middleware->web(append: [EndSessionsAfterPasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reported exceptions go to Sentry when SENTRY_LARAVEL_DSN is set
        // (ARCHITECTURE.md § 11); ignored ones (404s, validation) do not.
        Integration::handles($exceptions);

        // /admin and other guesses are plain 404s, logged as probes
        // (ONBOARDING_COMPLIANCE.md § 1).
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            $probes = config('console.probe_paths', []);

            if (is_array($probes) && $probes !== [] && $request->is(...array_filter($probes, 'is_string'))) {
                Log::notice('Console path probe', ['path' => '/'.$request->path(), 'ip' => $request->ip()]);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Split release layout (INSTALLER.md § 2): public files live in a sibling
// public_html/ folder. public_html/index.php sets the path for web requests;
// this file, written only into that layout, sets it for artisan and cron.
if (is_file(__DIR__.'/public-path.php')) {
    $publicPath = require __DIR__.'/public-path.php';

    if (is_string($publicPath)) {
        $app->usePublicPath($publicPath);
    }
}

return $app;
