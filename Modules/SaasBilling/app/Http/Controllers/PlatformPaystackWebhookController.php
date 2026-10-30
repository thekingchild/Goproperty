<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Jobs\ProcessPlatformPaystackWebhook;
use Modules\SaasBilling\Paystack\PlatformPaystack;

/**
 * POST /webhooks/paystack: Paystack's calls about GoProperty's OWN account
 * (BILLING_PAYMENTS.md § 4.2; P05-03 for SMS credit bundles, P06-03 for the
 * subscription). Outside the `web` group: no session, cookies or CSRF
 * token. No user and no policy: the HMAC-SHA512 signature of the raw body
 * with GoProperty's secret key proves the call.
 *
 * Every delivery is kept in `webhook_log` (organization null: the
 * platform's own account) before anything is processed:
 * - no platform key set: the plain 404 of an unknown address, nothing kept;
 * - a body far larger than any Paystack event: 413, not kept;
 * - signature wrong: kept as invalid, 401, never processed;
 * - signature right: kept, processing queued, 200 at once.
 */
final class PlatformPaystackWebhookController
{
    public const int MAX_BODY_BYTES = 65_536;

    public function __invoke(Request $request, PlatformPaystack $paystack): Response|JsonResponse
    {
        abort_unless($paystack->available(), 404);

        $body = $request->getContent();

        abort_if(strlen($body) > self::MAX_BODY_BYTES, 413);

        $signature = $request->header('X-Paystack-Signature');
        $valid = $paystack->signedByPaystack($body, is_string($signature) ? $signature : null);
        $decoded = json_decode($body, true);
        $event = is_array($decoded) && is_string($decoded['event'] ?? null) ? mb_substr($decoded['event'], 0, 60) : null;
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $reference = is_string($data['reference'] ?? null) ? mb_substr($data['reference'], 0, 100) : null;
        $now = CarbonImmutable::now();

        $id = DB::table('webhook_log')->insertGetId([
            'organization_id' => null,
            'provider' => 'paystack',
            // Kept exactly as received; bytes that are not UTF-8 cannot be stored as text, so only their fingerprint is.
            'payload' => mb_check_encoding($body, 'UTF-8') ? $body : '[not UTF-8: '.strlen($body).' bytes, sha256 '.hash('sha256', $body).']',
            'signature_valid' => $valid,
            'event' => $event !== null && mb_check_encoding($event, 'UTF-8') ? $event : null,
            'reference' => $reference !== null && mb_check_encoding($reference, 'UTF-8') ? $reference : null,
            'ip_address' => $request->ip() !== null ? mb_substr((string) $request->ip(), 0, 45) : null,
            'received_at' => $now,
            'processed_at' => $valid ? null : $now,
            'outcome' => $valid ? null : PaymentOutcome::Rejected->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! $valid) {
            return response()->noContent(401);
        }

        ProcessPlatformPaystackWebhook::dispatch((int) $id);

        return response()->json(['received' => true]);
    }
}
