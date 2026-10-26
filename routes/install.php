<?php

declare(strict_types=1);

use App\Http\Controllers\Install\InstallerController;
use App\Http\Middleware\EnsureInstallerOpen;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installer routes (INSTALLER.md § 3–5)
|--------------------------------------------------------------------------
|
| Outside the "web" group: these must work before APP_KEY exists, so they run
| without sessions, cookies or CSRF. Routes added after the unlock step
| (P01-09) use the web stack. All of them answer 404 once any installation
| marker says the install finished.
|
*/

Route::middleware(EnsureInstallerOpen::class)->group(function (): void {
    Route::get('install', [InstallerController::class, 'show'])->name('install.show');
});
