<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
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
});
