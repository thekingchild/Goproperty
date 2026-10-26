<?php

declare(strict_types=1);

use App\Http\Middleware\AwaitApplicationKey;
use App\Http\Middleware\EnsureSiteInstalled;
use App\Http\Middleware\ResumeImpersonation;
use App\Http\Middleware\SetOrganizationContext;
use App\Http\Middleware\StaffAccountState;
use App\Http\Middleware\UseConsoleSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], __DIR__.'/../routes/install.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before anything else: no APP_KEY → /install, then "being set up"
        // until the install is finalized (INSTALLER.md § 3).
        $middleware->prepend([
            AwaitApplicationKey::class,
            EnsureSiteInstalled::class,
            // Staff session cookie for console requests, before any session
            // starts (ONBOARDING_COMPLIANCE.md § 1).
            UseConsoleSession::class,
        ]);

        // /o/{organization} routes: the context must exist before route
        // model binding resolves organization-owned models (ARCHITECTURE.md § 4).
        $middleware->alias(['organization' => SetOrganizationContext::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetOrganizationContext::class);

        // A deactivated staff member's session ends before the console's
        // Authenticate middleware answers 403 (PLATFORM_CONSOLE.md § 1).
        $middleware->prependToPriorityList(AuthenticatesRequests::class, StaffAccountState::class);

        // GoProperty support sessions on the customer side: after the
        // session starts, before `auth` (PLATFORM_CONSOLE.md § 6).
        $middleware->web(append: [ResumeImpersonation::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResumeImpersonation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
