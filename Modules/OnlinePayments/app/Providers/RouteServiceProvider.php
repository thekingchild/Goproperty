<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * The module's routes: its pages in the `web` middleware group
 * (routes/web.php), and Paystack's webhook outside it (routes/webhooks.php):
 * no session, no cookies, no CSRF token for a server-to-server call
 * (ONBOARDING_COMPLIANCE.md § 4.2).
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function map(): void
    {
        Route::middleware('web')->group(module_path('OnlinePayments', '/routes/web.php'));
        Route::group([], module_path('OnlinePayments', '/routes/webhooks.php'));
    }
}
