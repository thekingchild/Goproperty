<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Http\Controllers;

use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\OnlinePayments\Jobs\ProcessPaystackWebhook;
use Modules\OnlinePayments\Models\PaystackWebhookLog;
use Modules\OnlinePayments\Webhooks\PaystackWebhooks;

/**
 * POST /webhooks/paystack/{webhook_token}: Paystack's calls about the
 * organization's own account (ONBOARDING_COMPLIANCE.md § 4.2, orchestrator
 * decision 2). Outside the `web` middleware group: no session, no cookies,
 * no CSRF token (also listed as a CSRF exception in bootstrap/app.php).
 * No user and no policy: the token finds the organization and the
 * HMAC-SHA512 signature with that organization's secret key proves the
 * call (Webhooks\PaystackWebhooks).
 *
 * - unknown or disconnected token: the plain 404 any unknown address gets;
 * - a body far larger than any Paystack event: 413, not kept;
 * - signature wrong: kept in `webhook_log` as invalid, 401, never processed;
 * - signature right: kept, processing queued (Jobs\ProcessPaystackWebhook),
 *   200 at once.
 */
final class PaystackWebhookController
{
    public function __invoke(Request $request, string $token, PaystackWebhooks $webhooks, OrganizationContext $context): Response|JsonResponse
    {
        $organization = $webhooks->organizationFor($token);

        abort_if($organization === null, 404);

        $body = $request->getContent();

        abort_if(strlen($body) > PaystackWebhooks::MAX_BODY_BYTES, 413);

        $signature = $request->header('X-Paystack-Signature');
        $log = $context->run($organization, fn (): PaystackWebhookLog => $webhooks->receive($body, is_string($signature) ? $signature : null, $request->ip()));

        if (! $log->signature_valid) {
            return response()->noContent(401);
        }

        ProcessPaystackWebhook::dispatch($organization->id, $log->id);

        return response()->json(['received' => true]);
    }
}
