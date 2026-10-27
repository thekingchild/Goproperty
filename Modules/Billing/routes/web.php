<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Livewire\BillIndex;
use Modules\Billing\Livewire\BillShow;

/*
 * Workspace pages of the Billing module. `organization` checks membership
 * (404 otherwise) and sets the organization context before route model
 * binding, so {bill} resolves only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('bills', BillIndex::class)->name('bills.index');
    Route::livewire('bills/{bill}', BillShow::class)->whereNumber('bill')->name('bills.show');
});
