<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\ReceiptDocumentController;
use Modules\Payments\Livewire\PaymentForm;
use Modules\Payments\Livewire\PaymentIndex;
use Modules\Payments\Livewire\PaymentShow;
use Modules\Payments\Livewire\ReceiptIndex;
use Modules\Payments\Livewire\ReceiptShow;

/*
 * Workspace pages of the Payments module. `organization` checks membership
 * (404 otherwise) and sets the organization context before route model
 * binding, so {payment} and {receipt} resolve only inside the organization
 * (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('payments', PaymentIndex::class)->name('payments.index');
    Route::livewire('payments/create', PaymentForm::class)->name('payments.create');
    Route::livewire('payments/{payment}', PaymentShow::class)->whereNumber('payment')->name('payments.show');

    // Receipts (P03-10b): the list, a receipt, and the receipt as printed.
    Route::livewire('receipts', ReceiptIndex::class)->name('receipts.index');
    Route::livewire('receipts/{receipt}', ReceiptShow::class)->whereNumber('receipt')->name('receipts.show');
    Route::controller(ReceiptDocumentController::class)->middleware('throttle:60,1')->group(function () {
        Route::get('receipts/{receipt}/document', 'document')->whereNumber('receipt')->name('receipts.document');
        Route::get('receipts/{receipt}/pdf', 'pdf')->whereNumber('receipt')->name('receipts.pdf');
        Route::get('receipts/{receipt}/void.pdf', 'voidPdf')->whereNumber('receipt')->name('receipts.void-pdf');
    });
});
