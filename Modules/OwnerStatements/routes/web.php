<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OwnerStatements\Http\Controllers\OwnerStatementPdfController;
use Modules\OwnerStatements\Livewire\OwnerStatementIndex;
use Modules\OwnerStatements\Livewire\OwnerStatementShow;
use Modules\OwnerStatements\Livewire\Portal\PortalStatementMonth;
use Modules\OwnerStatements\Livewire\Portal\PortalStatements;

/*
 * Owner statements in the agency's workspace (P08-05): an owner's months
 * and terms, one month with the Settled / Unsettled mark, and its PDF.
 * `organization` checks membership (404 otherwise) and sets the
 * organization context; {owner} is an owner profile's id there, checked by
 * OwnerStatementPolicy; {month} is "YYYY-MM", up to the current month.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('owners/{owner}/statements', OwnerStatementIndex::class)->whereNumber('owner')->name('owner-statements.index');
    Route::livewire('owners/{owner}/statements/{month}', OwnerStatementShow::class)->whereNumber('owner')->where('month', '\d{4}-\d{2}')->name('owner-statements.show');
    Route::get('owners/{owner}/statements/{month}/pdf', [OwnerStatementPdfController::class, 'workspace'])->whereNumber('owner')->where('month', '\d{4}-\d{2}')->name('owner-statements.pdf');
});

/*
 * Owner statements in the owner portal (P08-05; the portal's rules,
 * P03-15): read-only GET pages across every organization that manages
 * buildings of the user, through Properties' OwnerPortalAccess (the
 * "linked to my user" path); {owner} must be one of the user's owner
 * profiles, else 404. Every method call to these Livewire pages is refused
 * (Properties' Contracts\ReadOnlyOwnerPage).
 */
Route::middleware(['auth', 'verified'])->prefix('owner')->name('owner.')->group(function () {
    Route::livewire('statements', PortalStatements::class)->name('statements.index');
    Route::livewire('statements/{owner}/{month}', PortalStatementMonth::class)->whereNumber('owner')->where('month', '\d{4}-\d{2}')->name('statements.show');
    Route::get('statements/{owner}/{month}/pdf', [OwnerStatementPdfController::class, 'portal'])->whereNumber('owner')->where('month', '\d{4}-\d{2}')->name('statements.pdf');
});
