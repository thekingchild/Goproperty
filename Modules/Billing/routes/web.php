<?php

declare(strict_types=1);

use App\Http\Middleware\SignInPageHeaders;
use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\PayPageController;
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

    // The bill's invoice (P03-05c): web view, and the kept PDF through a signed link.
    Route::controller(InvoiceController::class)->middleware('throttle:60,1')->group(function () {
        Route::get('bills/{bill}/invoice', 'show')->whereNumber('bill')->name('bills.invoice');
        Route::get('bills/{bill}/invoice.pdf', 'download')->whereNumber('bill')->name('bills.invoice.pdf');
    });
});

/*
 * The public pay page (P03-05c; BRANDING_DOCUMENTS.md § 1 "Pay page"): no
 * sign-in, the payment reference is the key (found by Pay\PayPages, the
 * P01-04 lookup pattern), unknown references a plain 404. One rate-limit
 * bucket per IP (prefix "pay").
 */
Route::get('pay/{reference}', PayPageController::class)
    ->middleware([SignInPageHeaders::class, 'throttle:30,1,pay'])
    ->where('reference', '[A-Za-z0-9 -]{6,64}')
    ->name('pay.show');
