<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SaasBilling\Http\Controllers\OpenTransferLinkController;
use Modules\SaasBilling\Livewire\BillingTransferReview;
use Modules\SaasBilling\Livewire\BillingTransfers;

/*
 * Billing transfers (BILLING_PAYMENTS.md § 7).
 *
 * The emailed link carries only its token; after sign-in it opens the offer
 * in the receiving organization's workspace (or answers 404). Rate-limited
 * per IP like the member invitation link.
 */
Route::middleware(['auth', 'verified', 'throttle:30,1'])->group(function () {
    Route::get('billing-transfers/{token}', OpenTransferLinkController::class)
        ->where('token', '[A-Za-z0-9]{1,128}')
        ->name('billing-transfers.open');
});

/*
 * Workspace pages. `organization` checks membership (404 otherwise) and
 * sets the organization context before anything else, so {transfer} is
 * looked up only among the offers made to this organization
 * (Transfers\IncomingTransfers; ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('billing-transfers', BillingTransfers::class)->name('billing-transfers.index');
    Route::livewire('billing-transfers/{transfer}', BillingTransferReview::class)->whereNumber('transfer')->name('billing-transfers.show');
});
