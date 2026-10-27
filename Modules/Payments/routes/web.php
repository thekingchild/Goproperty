<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Livewire\PaymentForm;
use Modules\Payments\Livewire\PaymentIndex;
use Modules\Payments\Livewire\PaymentShow;

/*
 * Workspace pages of the Payments module. `organization` checks membership
 * (404 otherwise) and sets the organization context before route model
 * binding, so {payment} resolves only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('payments', PaymentIndex::class)->name('payments.index');
    Route::livewire('payments/create', PaymentForm::class)->name('payments.create');
    Route::livewire('payments/{payment}', PaymentShow::class)->whereNumber('payment')->name('payments.show');
});
