<?php

declare(strict_types=1);

use App\Http\Controllers\Install\DatabaseController;
use App\Http\Controllers\Install\FinishController;
use App\Http\Controllers\Install\InstallerController;
use App\Http\Controllers\Install\ResumeController;
use App\Http\Controllers\Install\ServerCheckController;
use App\Http\Controllers\Install\ServicesController;
use App\Http\Controllers\Install\SiteController;
use App\Http\Controllers\Install\SuperAdminController;
use App\Http\Controllers\Install\UnlockController;
use App\Http\Middleware\EnsureInstallerOpen;
use App\Http\Middleware\Installer\InstallerWebStack;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installer routes (INSTALLER.md § 3–5)
|--------------------------------------------------------------------------
|
| Outside the "web" group. Every route here answers 404, before any session
| or cookie, once any installation marker says the install finished; there
| is no other route that installs, resets or recovers (§ 9, tested by
| tests/Feature/Installer/NoHttpRecoveryTest.php).
|
| The first screen and the two unlock posts must work before APP_KEY
| exists, so they run without sessions, cookies or CSRF (the posts check
| same-origin instead). The wizard's steps run on the normal web stack with
| CSRF, in the installer's own encrypted file session, behind the grant.
|
*/

Route::middleware(EnsureInstallerOpen::class)->group(function (): void {
    Route::get('install', [InstallerController::class, 'show'])->name('install.show');
    Route::post('install/unlock/token', [UnlockController::class, 'token'])->name('install.unlock.token');
    Route::post('install/unlock/file', [UnlockController::class, 'file'])->name('install.unlock.file');

    Route::middleware(InstallerWebStack::class)->prefix('install')->name('install.')->group(function (): void {
        Route::get('resume', ResumeController::class)->name('resume');

        Route::get('server', [ServerCheckController::class, 'show'])->name('server');
        Route::post('server', [ServerCheckController::class, 'store'])->name('server.store');

        Route::get('site', [SiteController::class, 'show'])->name('site');
        Route::post('site', [SiteController::class, 'store'])->name('site.store');

        Route::get('database', [DatabaseController::class, 'show'])->name('database');
        Route::post('database', [DatabaseController::class, 'store'])->name('database.store');
        Route::post('database/reset', [DatabaseController::class, 'reset'])->name('database.reset');

        Route::get('services', [ServicesController::class, 'show'])->name('services');
        Route::post('services/mail', [ServicesController::class, 'mail'])->name('services.mail');
        Route::post('services/storage', [ServicesController::class, 'storage'])->name('services.storage');

        Route::get('admin', [SuperAdminController::class, 'show'])->name('admin');
        Route::post('admin', [SuperAdminController::class, 'store'])->name('admin.store');
        Route::post('admin/two-factor', [SuperAdminController::class, 'confirm'])->name('admin.two-factor');
        Route::post('admin/recovery-codes', [SuperAdminController::class, 'savedCodes'])->name('admin.recovery-codes');

        Route::get('finish', [FinishController::class, 'show'])->name('finish');
        Route::post('finish', [FinishController::class, 'store'])->name('finish.store');
    });
});
