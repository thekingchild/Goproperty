<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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
