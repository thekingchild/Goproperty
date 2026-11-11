<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\LateFees\Livewire\LateFeeSettingsPage;

/*
 * Settings → Late fees (PL-02, docs/plans/late-fees-2026-11-11.md).
 * `organization` checks membership (404 otherwise) and sets the
 * organization context.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('settings/payments/late-fees', LateFeeSettingsPage::class)->name('settings.payments.late-fees');
});
