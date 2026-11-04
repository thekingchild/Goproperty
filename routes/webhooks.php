<?php

declare(strict_types=1);

use App\Http\Controllers\Operations\UptimeCheckController;
use App\Http\Controllers\Webhooks\EmailWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * The email provider's bounce and complaint webhook (P05-04,
 * PLATFORM_CONSOLE.md § 5 "Delivery"). Not in the `web` group: no session
 * or cookies, and no CSRF token (also excepted in bootstrap/app.php).
 */
Route::post('webhooks/email/{provider}/{token}', EmailWebhookController::class)
    ->whereIn('provider', ['postmark', 'mailgun'])
    ->where('token', '[A-Za-z0-9_-]{24,128}')
    ->middleware('throttle:300,1,email-webhook')
    ->name('email.webhook');

/*
 * The uptime link for an outside monitor (P08-03, PLATFORM_CONSOLE.md § 2
 * "Who notices a stopped scheduler"): 200 while the scheduler runs, 503 once
 * it has stopped. The token is made in Console → Settings → Operations alerts.
 */
Route::get('up/scheduler/{token}', UptimeCheckController::class)
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:30,1')
    ->name('operations.uptime');
