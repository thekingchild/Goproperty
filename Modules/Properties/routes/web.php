<?php

declare(strict_types=1);

use App\Http\Middleware\SignInPageHeaders;
use Illuminate\Support\Facades\Route;
use Modules\Properties\Http\Controllers\OwnerInvitationController;
use Modules\Properties\Http\Controllers\OwnerWelcomeController;
use Modules\Properties\Livewire\OwnerPortal\OwnerBillShow;
use Modules\Properties\Livewire\OwnerPortal\OwnerBuildingShow;
use Modules\Properties\Livewire\OwnerPortal\OwnerHome;
use Modules\Properties\Livewire\OwnerPortal\OwnerLeaseShow;
use Modules\Properties\Livewire\OwnerProfileForm;
use Modules\Properties\Livewire\OwnerProfileIndex;
use Modules\Properties\Livewire\PropertyForm;
use Modules\Properties\Livewire\PropertyIndex;
use Modules\Properties\Livewire\PropertyShow;
use Modules\Properties\Livewire\UnitBulkForm;
use Modules\Properties\Livewire\UnitForm;
use Modules\Properties\Livewire\UnitIndex;

/*
 * Workspace pages of the Properties module. `organization` checks
 * membership (404 otherwise) and sets the organization context before
 * route model binding, so {owner}, {property} (a building's slug) and
 * {unit} resolve only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    // Property owners (DATABASE_SCHEMA.md § 1).
    Route::livewire('owners', OwnerProfileIndex::class)->name('owners.index');
    Route::livewire('owners/create', OwnerProfileForm::class)->name('owners.create');
    Route::livewire('owners/{owner}', OwnerProfileForm::class)->whereNumber('owner')->name('owners.edit');

    // Buildings (DATABASE_SCHEMA.md § 3), by slug; "create" is never a slug.
    Route::livewire('buildings', PropertyIndex::class)->name('buildings.index');
    Route::livewire('buildings/create', PropertyForm::class)->name('buildings.create');
    Route::livewire('buildings/{property}', PropertyShow::class)->name('buildings.show');
    Route::livewire('buildings/{property}/edit', PropertyForm::class)->name('buildings.edit');

    // Every unit across the buildings (P02-03; narrowed for assigned agents).
    Route::livewire('units', UnitIndex::class)->name('units.index');

    // Units of a building (DATABASE_SCHEMA.md § 3); the unit must be the building's.
    Route::livewire('buildings/{property}/units/create', UnitForm::class)->name('units.create');
    Route::livewire('buildings/{property}/units/bulk', UnitBulkForm::class)->name('units.bulk');
    Route::livewire('buildings/{property}/units/{unit}/edit', UnitForm::class)->whereNumber('unit')->name('units.edit');
});

/*
 * Owners' portal invitation link (P03-15; the tenant invitation's design,
 * P03-01b): for guests and signed-in accounts alike. It finds its
 * organization from the token; unknown, used, revoked and expired links all
 * get the same page. Each IP shares one bucket for opening (30 a minute)
 * and one for using (10 a minute) with the tenant portal's links.
 */
Route::middleware(SignInPageHeaders::class)->prefix('owner')->name('owner.')->group(function () {
    Route::controller(OwnerInvitationController::class)->group(function () {
        Route::get('invitations/{token}', 'show')
            ->where('token', '[A-Za-z0-9]{1,128}')
            ->middleware('throttle:30,1,portal-links')
            ->name('invitations.show');
        Route::post('invitations/{token}', 'store')
            ->where('token', '[A-Za-z0-9]{1,128}')
            ->middleware('throttle:10,1,portal-links-use')
            ->name('invitations.accept');
    });

    // After a new owner account is made: an optional passkey, then the portal.
    Route::get('welcome', [OwnerWelcomeController::class, 'show'])->middleware(['auth', 'verified'])->name('welcome');
});

/*
 * The owner portal (P03-15; ARCHITECTURE.md § 3–4): across every
 * organization that manages buildings of the user, so no `organization`
 * middleware and no organization in the URL. Every page reads through
 * OwnerPortal\OwnerPortfolios (the "linked to my user" path) and
 * OwnerProfilePolicy::viewAsOwner; a building, lease or bill that is not
 * one of the user's own buildings' answers 404. Read-only: GET pages only,
 * and every method call to these Livewire pages is refused
 * (Contracts\ReadOnlyOwnerPage).
 */
Route::middleware(['auth', 'verified'])->prefix('owner')->name('owner.')->group(function () {
    Route::livewire('/', OwnerHome::class)->name('home');
    Route::livewire('buildings/{building}', OwnerBuildingShow::class)->whereNumber('building')->name('buildings.show');
    Route::livewire('leases/{lease}', OwnerLeaseShow::class)->whereNumber('lease')->name('leases.show');
    Route::livewire('bills/{bill}', OwnerBillShow::class)->whereNumber('bill')->name('bills.show');
});
