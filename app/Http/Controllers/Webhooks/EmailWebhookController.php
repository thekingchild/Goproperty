<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Mail\Suppression\EmailWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/email/{provider}/{token}: the email provider's bounce and
 * complaint calls (P05-04; PLATFORM_CONSOLE.md § 5 "Delivery"). Outside the
 * `web` group (no session, no CSRF token); no user and no policy: the
 * secret token in the URL (and Mailgun's signature) proves the caller
 * (App\Mail\Suppression\EmailWebhooks).
 *
 * - unknown provider, no token configured or a wrong token: plain 404;
 * - a body far larger than any provider event: 413, not kept;
 * - a Mailgun signature that does not match: kept as invalid, 401;
 * - otherwise kept, processed at once (a few rows), 200.
 */
final class EmailWebhookController
{
    public function __invoke(Request $request, string $provider, string $token, EmailWebhooks $webhooks): Response|JsonResponse
    {
        abort_unless($webhooks->tokenMatches($token), 404);

        $body = $request->getContent();

        abort_if(strlen($body) > EmailWebhooks::MAX_BODY_BYTES, 413);

        $delivery = $webhooks->receive($provider, $body, $request->ip());

        if (! $delivery->signature_valid) {
            $delivery->forceFill(['processed_at' => now(), 'outcome' => 'rejected'])->save();

            return response()->noContent(401);
        }

        return response()->json(['received' => true, 'suppressed' => $webhooks->process($delivery)]);
    }
}
