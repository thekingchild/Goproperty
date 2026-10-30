<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Http\Controllers\WhatsAppWebhookController;
use Modules\WhatsApp\Webhooks\WhatsAppWebhooks;

/*
 * Meta's webhook for an organization's own WhatsApp account,
 * /webhooks/whatsapp/{ulid} (ARCHITECTURE.md § 6.7): GET is Meta's
 * verify-token handshake, POST its signed status calls. Not in the `web`
 * group: no session or cookies, and no CSRF token (also excepted in
 * bootstrap/app.php). A generous per-IP limit: Meta retries what is refused.
 */
Route::controller(WhatsAppWebhookController::class)->where(['ulid' => WhatsAppWebhooks::ULID_PATTERN])->group(function () {
    Route::get('webhooks/whatsapp/{ulid}', 'verify')->middleware('throttle:60,1,whatsapp-webhook-verify')->name('whatsapp.webhook.verify');
    Route::post('webhooks/whatsapp/{ulid}', 'receive')->middleware('throttle:600,1,whatsapp-webhook')->name('whatsapp.webhook');
});
