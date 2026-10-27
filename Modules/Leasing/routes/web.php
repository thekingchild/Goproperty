<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Leasing\Http\Controllers\LeaseContractController;
use Modules\Leasing\Livewire\ContractSettingsPage;
use Modules\Leasing\Livewire\LeaseForm;
use Modules\Leasing\Livewire\LeaseIndex;
use Modules\Leasing\Livewire\LeaseShow;

/*
 * Workspace pages of the Leasing module. `organization` checks membership
 * (404 otherwise) and sets the organization context before route model
 * binding, so {lease} resolves only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('leases', LeaseIndex::class)->name('leases.index');
    Route::livewire('leases/create', LeaseForm::class)->name('leases.create');
    Route::livewire('leases/{lease}', LeaseShow::class)->whereNumber('lease')->name('leases.show');
    Route::livewire('leases/{lease}/edit', LeaseForm::class)->whereNumber('lease')->name('leases.edit');
    Route::livewire('leases/{lease}/renew', LeaseForm::class)->whereNumber('lease')->name('leases.renew');

    // The contract PDF (P03-03b): the kept file through a signed link.
    Route::get('leases/{lease}/contract', LeaseContractController::class)->whereNumber('lease')->middleware('throttle:60,1')->name('leases.contract');

    // Settings → Lease contract: the agency's clause template (org-owner, org-admin).
    Route::livewire('settings/documents/lease-contract', ContractSettingsPage::class)->name('settings.documents.lease-contract');
});
