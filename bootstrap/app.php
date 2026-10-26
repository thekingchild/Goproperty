<?php

declare(strict_types=1);

use App\Http\Middleware\AwaitApplicationKey;
use App\Http\Middleware\EnsureSiteInstalled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
