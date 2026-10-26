<?php

declare(strict_types=1);

use App\Http\Controllers\Marketing\MarketingController;
use App\Http\Controllers\Marketing\SeoController;
use App\Http\Middleware\PublicPageHeaders;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| One domain, path-based surfaces (ONBOARDING_COMPLIANCE.md § 1)
|--------------------------------------------------------------------------
|
| /                       marketing, signup                 public
| /login                  one sign-in for every customer    Fortify
| /o/{organization}/…     agency or owner workspace         org members
| /portal/…               tenant portal, across agencies    tenants
| /owner/…                owner portal, across agencies     invited owners
| /pay/{reference}        branded pay page                  no sign-in
| /verify/{code}          document verification             no sign-in
| /{ADMIN_PATH}           platform console (Filament)       staff guard
|
| The console path and host come from config (app.admin_path,
| app.admin_domain); never hard-code them.
|
*/

// Public marketing pages (UX_DESIGN.md § 4, PLATFORM_CONSOLE.md § 9.3–9.5).
Route::middleware(PublicPageHeaders::class)->controller(MarketingController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('privacy', 'privacy')->name('privacy');
    Route::get('terms', 'terms')->name('terms');
});

Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // `organization` checks membership (404 otherwise) and sets the
    // organization context before route model binding (ARCHITECTURE.md § 4).
    Route::middleware('organization')->prefix('o/{organization}')->name('workspace.')->group(function () {
        Route::view('/', 'surfaces.workspace')->name('home');
    });

    // Cross-organization portals: they never set an organization context
    // and reach records through the signed-in user's own links (ARCHITECTURE.md § 4).
    Route::view('portal', 'surfaces.portal')->name('portal.home');
    Route::view('owner', 'surfaces.owner')->name('owner.home');
});

// No sign-in. Not found until the pay page (P04) and document
// verification (P03) exist; the routes are named so links can be built.
Route::get('pay/{reference}', fn () => abort(404))
    ->where('reference', '[A-Za-z0-9-]{6,64}')
    ->name('pay.show');

Route::get('verify/{code}', fn () => abort(404))
    ->where('code', '[A-Za-z0-9-]{6,64}')
    ->name('documents.verify');

require __DIR__.'/settings.php';
