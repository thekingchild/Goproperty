<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OnlinePayments\Http\Controllers\PayOnlineController;
use Modules\OnlinePayments\Livewire\PaystackSettingsPage;

/*
 * Settings → Online payments (the organization's own Paystack account,
 * org-owners only). `organization` checks membership (404 otherwise) and
 * sets the organization context (ARCHITECTURE.md § 4).
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('settings/paystack', PaystackSettingsPage::class)->name('settings.payments.paystack');
});

/*
 * Paying a bill online from its public pay page (no sign-in): the signed
 * "pay online" page and its form, and the return from Paystack's checkout.
 * Rate-limited per IP.
 */
Route::controller(PayOnlineController::class)->where(['reference' => '[A-Za-z0-9-]{6,64}'])->group(function () {
    Route::get('pay/{reference}/online', 'show')->middleware('throttle:30,1,pay-online')->name('online-payments.pay');
    Route::post('pay/{reference}/online', 'start')->middleware('throttle:10,1,pay-online-start')->name('online-payments.start');
    Route::get('pay/{reference}/online/return', 'return')->middleware('throttle:30,1,pay-online-return')->name('online-payments.return');
});
