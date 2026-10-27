<?php

declare(strict_types=1);

use App\Http\Middleware\SignInPageHeaders;
use Illuminate\Support\Facades\Route;
use Modules\Tenants\Http\Controllers\PortalInvitationController;
use Modules\Tenants\Http\Controllers\PortalInvoiceController;
use Modules\Tenants\Http\Controllers\PortalWelcomeController;
use Modules\Tenants\Http\Controllers\SignInLinkController;
use Modules\Tenants\Livewire\Portal\PortalBills;
use Modules\Tenants\Livewire\Portal\PortalBillShow;
use Modules\Tenants\Livewire\Portal\PortalHome;
use Modules\Tenants\Livewire\Portal\PortalLeaseShow;
use Modules\Tenants\Livewire\TenantForm;
use Modules\Tenants\Livewire\TenantIndex;
use Modules\Tenants\Livewire\TenantShow;

/*
 * Workspace pages of the Tenants module (P03-01a). `organization` checks
 * membership (404 otherwise) and sets the organization context before
 * route model binding, so {tenant} resolves only inside the organization
 * (ARCHITECTURE.md § 4). A tenant's record opens archived too (read-only,
 * with Restore); the edit page only for an active tenant.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('tenants', TenantIndex::class)->name('tenants.index');
    Route::livewire('tenants/create', TenantForm::class)->name('tenants.create');
    Route::livewire('tenants/{tenant}', TenantShow::class)->whereNumber('tenant')->withTrashed()->name('tenants.show');
    Route::livewire('tenants/{tenant}/edit', TenantForm::class)->whereNumber('tenant')->name('tenants.edit');
});

/*
 * Tenants' portal links (P03-01b, ONBOARDING_COMPLIANCE.md § 2 "Tenants"):
 * the invitation link and the agency's one-time sign-in link, for guests
 * and signed-in accounts alike. They find their organization from the
 * token; unknown, used, revoked and expired links all get the same page.
 * Each IP shares one bucket for opening (30 a minute) and one for using
 * (10 a minute).
 */
Route::middleware(SignInPageHeaders::class)->prefix('portal')->name('portal.')->group(function () {
    Route::controller(PortalInvitationController::class)->group(function () {
        Route::get('invitations/{token}', 'show')
            ->where('token', '[A-Za-z0-9]{1,128}')
            ->middleware('throttle:30,1,portal-links')
            ->name('invitations.show');
        Route::post('invitations/{token}', 'store')
            ->where('token', '[A-Za-z0-9]{1,128}')
            ->middleware('throttle:10,1,portal-links-use')
            ->name('invitations.accept');
    });

    Route::controller(SignInLinkController::class)->group(function () {
        Route::get('sign-in/{token}', 'show')
            ->where('token', '[A-Za-z0-9]{1,128}')
            ->middleware('throttle:30,1,portal-links')
            ->name('sign-in-links.show');
        Route::post('sign-in/{token}', 'store')
            ->where('token', '[A-Za-z0-9]{1,128}')
            ->middleware('throttle:10,1,portal-links-use')
            ->name('sign-in-links.use');
    });

    // After a new tenant account is made: an optional passkey, then the portal.
    Route::get('welcome', PortalWelcomeController::class)->middleware(['auth', 'verified'])->name('welcome');
});

/*
 * The tenant portal (P03-11a; ARCHITECTURE.md § 3–4): across every agency
 * the user rents from, so no `organization` middleware and no organization
 * in the URL. Every page reads through Portal\PortalTenancies (the "linked
 * to my user" path) and checks TenantProfilePolicy::viewAsTenant for the
 * record it shows; a bill or lease that is not the user's own answers 404.
 */
Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::livewire('/', PortalHome::class)->name('home');
    Route::livewire('bills', PortalBills::class)->name('bills.index');
    Route::livewire('bills/{bill}', PortalBillShow::class)->whereNumber('bill')->name('bills.show');
    Route::get('bills/{bill}/invoice', [PortalInvoiceController::class, 'show'])->whereNumber('bill')->middleware('throttle:60,1')->name('bills.invoice');
    Route::livewire('leases/{lease}', PortalLeaseShow::class)->whereNumber('lease')->name('leases.show');
});
