<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Import\Http\Controllers\ImportDownloadController;
use Modules\Import\Livewire\ImportBatchPage;
use Modules\Import\Livewire\ImportIndex;
use Modules\Import\Livewire\NewImport;

/*
 * Workspace pages of the Import module (DATA_IMPORT.md § 2). `organization`
 * checks membership (404 otherwise) and sets the organization context
 * before route model binding, so {batch} resolves only inside the
 * organization (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('imports', ImportIndex::class)->name('imports.index');
    Route::livewire('imports/new', NewImport::class)->name('imports.create');
    Route::get('imports/template', [ImportDownloadController::class, 'template'])->name('imports.template');
    Route::livewire('imports/{batch}', ImportBatchPage::class)->whereNumber('batch')->name('imports.show');
    Route::get('imports/{batch}/problems', [ImportDownloadController::class, 'errors'])->whereNumber('batch')->name('imports.errors');
});
