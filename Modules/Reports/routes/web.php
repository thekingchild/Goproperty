<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportPdfController;
use Modules\Reports\Livewire\ReportsDashboard;

/*
 * The customer dashboards (P08-01): the Reports page and its PDF, with the
 * filters (from, to, building, days) in the query string. `organization`
 * checks membership (404 otherwise) and sets the organization context;
 * ReportPolicy checks `reports.view`.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('reports', ReportsDashboard::class)->name('reports.index');
    Route::get('reports/pdf', ReportPdfController::class)->middleware('throttle:20,1')->name('reports.pdf');
});
