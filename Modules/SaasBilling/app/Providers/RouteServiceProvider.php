<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * The module's routes: its pages in the `web` middleware group
 * (routes/web.php), and Paystack's webhook for GoProperty's own account
 * outside it (routes/webhooks.php): no session, cookies or CSRF token.
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function map(): void
    {
        Route::middleware('web')->group(module_path('SaasBilling', '/routes/web.php'));
        Route::group([], module_path('SaasBilling', '/routes/webhooks.php'));
    }
}
