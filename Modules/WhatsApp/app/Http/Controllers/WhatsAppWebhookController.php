<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\WhatsApp\Jobs\ProcessWhatsAppStatuses;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Webhooks\WhatsAppWebhooks;

/**
 * /webhooks/whatsapp/{ulid}: Meta's calls about the organization's own
 * WhatsApp account (ARCHITECTURE.md § 6.7). Outside the `web` middleware
 * group: no session, no cookies, no CSRF token (also excepted in
 * bootstrap/app.php). No user and no policy: the ULID finds the
 * organization, and the verify token (GET) or the app secret's signature
 * (POST) proves the call (Webhooks\WhatsAppWebhooks).
 *
 * - unknown or disconnected address: the plain 404 any unknown address gets;
 * - GET with the right verify token: Meta's challenge back (200), else 403;
 * - POST larger than any Meta call: 413;
 * - POST with a missing or wrong `X-Hub-Signature-256`: 401, nothing in it
 *   is read or recorded;
 * - POST signed: its statuses queued (Jobs\ProcessWhatsAppStatuses), 200 at once.
 */
final class WhatsAppWebhookController
{
    public function verify(Request $request, string $ulid, WhatsAppWebhooks $webhooks, OrganizationContext $context): Response
    {
        $organization = $webhooks->organizationFor($ulid);

        abort_if($organization === null, 404);

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $ok = $context->run($organization, static function () use ($webhooks, $mode, $token): bool {
            $connection = WhatsAppConnection::query()->first();

            return $connection instanceof WhatsAppConnection && $webhooks->verifies($connection, is_string($mode) ? $mode : null, is_string($token) ? $token : null);
        });

        abort_unless($ok && is_string($challenge) && preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $challenge) === 1, 403);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $ulid, WhatsAppWebhooks $webhooks, OrganizationContext $context): Response|JsonResponse
    {
        $organization = $webhooks->organizationFor($ulid);

        abort_if($organization === null, 404);

        $body = $request->getContent();

        abort_if(strlen($body) > WhatsAppWebhooks::MAX_BODY_BYTES, 413);

        $signature = $request->header('X-Hub-Signature-256');
        $statuses = $context->run($organization, static function () use ($webhooks, $body, $signature): ?array {
            $connection = WhatsAppConnection::query()->first();

            if (! $connection instanceof WhatsAppConnection || ! $webhooks->signed($connection, $body, is_string($signature) ? $signature : null)) {
                return null;
            }

            WhatsAppConnection::query()->whereKey($connection->id)->update(['last_event_at' => now()]);

            return $webhooks->statuses($connection, $body);
        });

        if ($statuses === null) {
            return response()->noContent(401);
        }

        if ($statuses !== []) {
            ProcessWhatsAppStatuses::dispatch($organization->id, $statuses);
        }

        return response()->json(['received' => true]);
    }
}
