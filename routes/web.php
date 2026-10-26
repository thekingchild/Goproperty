<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\Marketing\MarketingController;
use App\Http\Controllers\Marketing\SeoController;
use App\Http\Controllers\Onboarding\RegisterController;
use App\Http\Controllers\Onboarding\VerifyEmailController;
use App\Http\Controllers\Workspace\DashboardController;
use App\Http\Controllers\Workspace\SwitchOrganizationController;
use App\Http\Controllers\Workspace\WorkspaceHomeController;
use App\Http\Middleware\PublicPageHeaders;
use App\Http\Middleware\SignInPageHeaders;
use App\Livewire\Onboarding\CreateOrganization;
use App\Livewire\Onboarding\SetupWizard;
use App\Theme\BrandAssets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| One domain, path-based surfaces (ONBOARDING_COMPLIANCE.md § 1)
|--------------------------------------------------------------------------
|
| /                       marketing                         public
| /register, /signup/…    signup (account, organization)    Fortify + wizard
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

// "Forgot password" takes an email or phone number and never reveals an
// account (UX_DESIGN.md § 3.3); it replaces Fortify's email-only route.
Route::post('forgot-password', PasswordResetLinkController::class)
    ->middleware(['guest:web', 'throttle:6,1', SignInPageHeaders::class])
    ->name('password.email');

// Signup (ONBOARDING_COMPLIANCE.md § 2). These replace Fortify's register
// post and email verification link: signup never reveals whether an
// address has an account, and signs nobody in until the link is opened.
Route::middleware(['guest:web', SignInPageHeaders::class])->group(function () {
    Route::post('register', [RegisterController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');
    Route::get('register/check-email', [RegisterController::class, 'sent'])->name('register.sent');
});

Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Cacheable files: no session, no cookies.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, PreventRequestForgery::class])->group(function () {
    Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
    Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

    // Platform brand images (PLATFORM_CONSOLE.md § 9.2).
    Route::get('brand/{role}', BrandAssetController::class)
        ->whereIn('role', [...array_keys(BrandAssets::ROLES), 'apple-touch-icon'])
        ->name('brand.asset');
    Route::redirect('favicon.ico', '/brand/favicon');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Organization picker; signing in lands on a workspace when there is
    // one to choose (App\Tenancy\WorkspaceLanding).
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Signup, organization step; later steps run in the new workspace.
    Route::livewire('signup/organization', CreateOrganization::class)->name('onboarding.organization');

    // Organization switcher: opens a workspace and remembers it (404 for non-members).
    Route::post('organizations/{slug}/switch', SwitchOrganizationController::class)
        ->where('slug', '[A-Za-z0-9-]{1,64}')
        ->name('organizations.switch');

    // `organization` checks membership (404 otherwise) and sets the
    // organization context before route model binding (ARCHITECTURE.md § 4).
    Route::middleware('organization')->prefix('o/{organization}')->name('workspace.')->group(function () {
        Route::get('/', WorkspaceHomeController::class)->name('home');

        // Signup wizard, Tier step onwards (ONBOARDING_COMPLIANCE.md § 2).
        Route::livewire('setup', SetupWizard::class)->name('setup');
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
