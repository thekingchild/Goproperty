<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SaasBilling\Http\Controllers\PlatformPaystackWebhookController;

/*
 * Paystack's webhook for GoProperty's OWN account (BILLING_PAYMENTS.md
 * § 4.2; P05-03): SMS credit bundles now, the subscription in P06-03.
 * Not in the `web` group: no session or cookies, and no CSRF token (also
 * excepted in bootstrap/app.php). A generous per-IP limit: Paystack
 * retries what is refused. Organizations' own accounts have their own
 * address, /webhooks/paystack/{token} (Modules\OnlinePayments).
 */
Route::post('webhooks/paystack', PlatformPaystackWebhookController::class)
    ->middleware('throttle:300,1,platform-paystack-webhook')
    ->name('saasbilling.paystack-webhook');
