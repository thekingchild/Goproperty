<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SaasBilling\Http\Controllers\SmsCreditReturnController;
use Modules\SaasBilling\Http\Controllers\SubscriptionPaymentReturnController;

/*
 * P02-04's billing transfers became the management handover (P03-14,
 * BILLING_PAYMENTS.md § 7): their workspace pages send owners to the
 * handovers page. Membership is checked first (`organization`), so an
 * outsider still gets 404. The old emailed links (/billing-transfers/{token})
 * are gone with the offers they carried.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->group(function () {
    Route::permanentRedirect('billing-transfers', '/o/{organization}/handovers');
    Route::permanentRedirect('billing-transfers/{transfer}', '/o/{organization}/handovers')->whereNumber('transfer');
});

/*
 * The return from Paystack's checkout of an SMS credit bundle (P05-03) or
 * a subscription invoice (P06-03): settles the payment (verified with
 * Paystack) and goes back to the settings page. Rate-limited per member.
 */
Route::middleware(['auth', 'verified', 'organization', 'throttle:30,1'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::get('settings/sms-credits/paid', SmsCreditReturnController::class)->name('settings.sms-credits.paid');
    Route::get('settings/subscription/paid', SubscriptionPaymentReturnController::class)->name('settings.subscription.paid');
});
