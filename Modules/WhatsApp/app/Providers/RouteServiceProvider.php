<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * The module's routes: its settings pages in the `web` middleware group
 * (routes/web.php), and Meta's webhook outside it (routes/webhooks.php): no
 * session, no cookies, no CSRF token for a server-to-server call.
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function map(): void
    {
        Route::middleware('web')->group(module_path('WhatsApp', '/routes/web.php'));
        Route::group([], module_path('WhatsApp', '/routes/webhooks.php'));
    }
}
