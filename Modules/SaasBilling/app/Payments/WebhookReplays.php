<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Payments;

use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Contracts\PlatformWebhookReplays;
use Modules\SaasBilling\Jobs\ProcessPlatformPaystackWebhook;

/** See Contracts\PlatformWebhookReplays (P06-03). */
final readonly class WebhookReplays implements PlatformWebhookReplays
{
    public function replay(int $webhookLogId): bool
    {
        $replayable = DB::table('webhook_log')
            ->where('id', $webhookLogId)
            ->whereNull('organization_id')
            ->where('provider', 'paystack')
            ->where('signature_valid', true)
            ->exists();

        if (! $replayable) {
            return false;
        }

        DB::table('webhook_log')->where('id', $webhookLogId)->update(['processing_error' => null, 'updated_at' => now()]);
        ProcessPlatformPaystackWebhook::dispatch($webhookLogId, replay: true);

        return true;
    }
}
