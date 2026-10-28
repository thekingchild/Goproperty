<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Handovers\Http\Controllers\OpenHandoverLinkController;
use Modules\Handovers\Livewire\HandoverIndex;
use Modules\Handovers\Livewire\HandoverShow;

/*
 * Management handovers (BILLING_PAYMENTS.md § 7).
 *
 * The emailed link carries only its token; after sign-in it opens the
 * offer in the receiving organization's workspace (or answers 404).
 * Rate-limited per IP like the member invitation link.
 */
Route::middleware(['auth', 'verified', 'throttle:30,1'])->group(function () {
    Route::get('handovers/{token}', OpenHandoverLinkController::class)
        ->where('token', '[A-Za-z0-9]{1,128}')
        ->name('handovers.open');
});

/*
 * Workspace pages. `organization` checks membership (404 otherwise) and
 * sets the organization context before anything else, so {handover} is
 * looked up only among this organization's own handovers and those
 * offered to it (Handovers\IncomingHandovers; ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('handovers', HandoverIndex::class)->name('handovers.index');
    Route::livewire('handovers/{handover}', HandoverShow::class)->whereNumber('handover')->name('handovers.show');
});
