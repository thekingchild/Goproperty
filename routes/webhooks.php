<?php

declare(strict_types=1);

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
