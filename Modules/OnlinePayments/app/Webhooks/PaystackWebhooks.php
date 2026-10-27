<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Webhooks;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\OnlinePayments\Enums\WebhookOutcome;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\Models\PaystackWebhookLog;

/**
 * Receives deliveries to an organization's webhook address,
 * /webhooks/paystack/{webhook_token} (orchestrator decision 2;
 * BILLING_PAYMENTS.md § 4.2; ARCHITECTURE.md § 10):
 *
 * - organizationFor(): the token finds the organization by one unscoped
 *   read of `organization_paystack_connections.organization_id` (the
 *   P01-04 lookup pattern; no scope removed), disconnected connections
 *   excluded; everything else happens inside that organization's context.
 * - receive(): checks the X-Paystack-Signature header — HMAC-SHA512 of the
 *   RAW body with THAT organization's secret key, compared in constant
 *   time — and keeps the delivery in `webhook_log` (raw body, event,
 *   reference, whether the signature matched) before anything is
 *   processed. A delivery signed with any other key (another
 *   organization's, GoProperty's own, a forger's) is kept, marked invalid
 *   and rejected; it is never processed.
 *
 * Processing is queued (Jobs\ProcessPaystackWebhook) so Paystack gets its
 * 2xx at once.
 */
final readonly class PaystackWebhooks
{
    /** Paystack's events are a few kilobytes; anything far larger is not one. */
    public const int MAX_BODY_BYTES = 65_536;

    public const string TOKEN_PATTERN = '[a-z0-9]{48}';

    /** The organization whose connection has this webhook token (not disconnected), or null. */
    public function organizationFor(string $token): ?Organization
    {
        if (preg_match('/^'.self::TOKEN_PATTERN.'$/', $token) !== 1) {
            return null;
        }

        $organizationId = DB::table('organization_paystack_connections')
            ->where('webhook_token', $token)
            ->where('status', '!=', 'disconnected')
            ->value('organization_id');

        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        return $organization instanceof Organization ? $organization : null;
    }

    /** Checks the signature and keeps the delivery; inside the organization's context. */
    public function receive(string $rawBody, ?string $signature, ?string $ipAddress): PaystackWebhookLog
    {
        $connection = PaystackConnection::query()->first();
        $secret = $connection instanceof PaystackConnection && $connection->connected() ? (string) $connection->secret_key : '';
        $signature = strtolower(trim((string) $signature));
        $valid = $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);

        $decoded = json_decode($rawBody, true);
        $event = is_array($decoded) && is_string($decoded['event'] ?? null) ? mb_substr($decoded['event'], 0, 60) : null;
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $reference = is_string($data['reference'] ?? null) ? mb_substr($data['reference'], 0, 100) : null;

        $log = new PaystackWebhookLog;
        // Kept exactly as received; bytes that are not UTF-8 cannot be stored as text, so only their fingerprint is.
        $log->payload = mb_check_encoding($rawBody, 'UTF-8') ? $rawBody : '[not UTF-8: '.strlen($rawBody).' bytes, sha256 '.hash('sha256', $rawBody).']';
        $log->signature_valid = $valid;
        $log->event = $event !== null && mb_check_encoding($event, 'UTF-8') ? $event : null;
        $log->reference = $reference !== null && mb_check_encoding($reference, 'UTF-8') ? $reference : null;
        $log->ip_address = $ipAddress !== null ? mb_substr($ipAddress, 0, 45) : null;
        $log->received_at = CarbonImmutable::now();

        if (! $valid) {
            $log->processed_at = CarbonImmutable::now();
            $log->outcome = WebhookOutcome::Rejected;
        }

        $log->save();

        if ($valid) {
            PaystackConnection::query()->whereKey($connection->id)->update(['last_event_at' => CarbonImmutable::now()]);
        }

        return $log;
    }
}
