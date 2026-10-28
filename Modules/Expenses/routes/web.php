<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Expenses\Http\Controllers\ExpenseReceiptFileController;
use Modules\Expenses\Livewire\ExpenseForm;
use Modules\Expenses\Livewire\ExpenseIndex;
use Modules\Expenses\Livewire\ExpenseShow;

/*
 * Workspace pages of the Expenses module (P03-12).
 * `organization` checks membership (404 otherwise) and sets the
 * organization context before route model binding, so {expense} and
 * {receipt} resolve only inside the organization (ARCHITECTURE.md § 4).
 * Archived expenses open too (read-only, with Restore), their receipt
 * files too; editing one does not.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('expenses', ExpenseIndex::class)->name('expenses.index');
    Route::livewire('expenses/create', ExpenseForm::class)->name('expenses.create');
    Route::livewire('expenses/{expense}', ExpenseShow::class)->whereNumber('expense')->withTrashed()->name('expenses.show');
    Route::livewire('expenses/{expense}/edit', ExpenseForm::class)->whereNumber('expense')->name('expenses.edit');
    Route::get('expenses/{expense}/receipts/{receipt}', [ExpenseReceiptFileController::class, 'show'])->whereNumber(['expense', 'receipt'])->withTrashed()->middleware('throttle:60,1')->name('expenses.receipts.show');
    Route::get('expenses/{expense}/receipts/{receipt}/thumb', [ExpenseReceiptFileController::class, 'thumb'])->whereNumber(['expense', 'receipt'])->withTrashed()->middleware('throttle:60,1')->name('expenses.receipts.thumb');
});
