<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Collections\Http\Controllers\StatementFileController;
use Modules\Collections\Livewire\StatementIndex;
use Modules\Collections\Livewire\StatementShow;
use Modules\Collections\Livewire\StatementUpload;

/*
 * Workspace pages of the Collections module (bank statements, P03-07a).
 * `organization` checks membership (404 otherwise) and sets the
 * organization context before route model binding, so {statement}
 * resolves only inside the organization (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('statements', StatementIndex::class)->name('statements.index');
    Route::livewire('statements/upload', StatementUpload::class)->name('statements.create');
    Route::livewire('statements/{statement}', StatementShow::class)->whereNumber('statement')->name('statements.show');
    Route::get('statements/{statement}/file', StatementFileController::class)->whereNumber('statement')->middleware('throttle:30,1')->name('statements.file');
});
