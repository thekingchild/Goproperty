<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/** The module's web routes (routes/web.php), in the `web` middleware group. */
final class RouteServiceProvider extends ServiceProvider
{
    public function map(): void
    {
        Route::middleware('web')->group(module_path('SaasBilling', '/routes/web.php'));
    }
}
