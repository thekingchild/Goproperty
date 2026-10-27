<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OnlinePayments\Http\Controllers\PaystackWebhookController;
use Modules\OnlinePayments\Webhooks\PaystackWebhooks;

/*
 * Paystack's webhook for an organization's own account,
 * /webhooks/paystack/{webhook_token} (orchestrator decision 2). Not in the
 * `web` group: no session or cookies, and no CSRF token (also excepted in
 * bootstrap/app.php). A generous per-IP limit: Paystack retries what is
 * refused.
 */
Route::post('webhooks/paystack/{token}', PaystackWebhookController::class)
    ->where('token', PaystackWebhooks::TOKEN_PATTERN)
    ->middleware('throttle:300,1,paystack-webhook')
    ->name('online-payments.webhook');
