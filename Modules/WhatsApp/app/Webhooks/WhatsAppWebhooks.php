<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Webhooks;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Modules\WhatsApp\Models\WhatsAppConnection;
use SensitiveParameter;

/**
 * Meta's calls to an organization's webhook address,
 * /webhooks/whatsapp/{ulid} (ARCHITECTURE.md § 6.7):
 *
 * - organizationFor(): the ULID finds the organization by one unscoped read
 *   of `organization_whatsapp_connections.organization_id` (the P01-04
 *   lookup pattern; no scope removed), disconnected connections excluded;
 *   everything else happens inside that organization's context.
 * - verifies(): Meta's GET handshake (`hub.mode=subscribe`, the
 *   connection's verify token compared in constant time).
 * - signed(): the `X-Hub-Signature-256` header, `sha256=` and the
 *   HMAC-SHA256 of the RAW body with THAT organization's app secret,
 *   compared in constant time. A call signed with any other secret, or not
 *   signed, is refused and nothing in it is read.
 * - statuses(): the message statuses in a signed call for this
 *   connection's phone number.
 */
final readonly class WhatsAppWebhooks
{
    /** Meta's status calls are a few kilobytes; a batch is rarely more than a few dozen. */
    public const int MAX_BODY_BYTES = 262_144;

    public const string ULID_PATTERN = '[0-9a-hjkmnp-tv-z]{26}';

    public function organizationFor(string $ulid): ?Organization
    {
        if (preg_match('/^'.self::ULID_PATTERN.'$/', $ulid) !== 1) {
            return null;
        }

        $organizationId = DB::table('organization_whatsapp_connections')
            ->where('webhook_ulid', $ulid)
            ->where('status', '!=', 'disconnected')
            ->value('organization_id');

        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        return $organization instanceof Organization ? $organization : null;
    }

    public function verifies(WhatsAppConnection $connection, ?string $mode, #[SensitiveParameter] ?string $token): bool
    {
        return $mode === 'subscribe' && is_string($token) && $token !== '' && hash_equals($connection->webhook_verify_token, $token);
    }

    public function signed(WhatsAppConnection $connection, string $rawBody, ?string $header): bool
    {
        $secret = $connection->connected() ? (string) $connection->app_secret : '';
        $header = strtolower(trim((string) $header));

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    /**
     * The statuses Meta reports for this connection's phone number.
     *
     * @return list<array{id: string, status: string, timestamp: int|null, error: string|null}>
     */
    public function statuses(WhatsAppConnection $connection, string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        $statuses = [];

        if (! is_array($decoded) || ($decoded['object'] ?? null) !== 'whatsapp_business_account' || ! is_array($decoded['entry'] ?? null)) {
            return [];
        }

        foreach ($decoded['entry'] as $entry) {
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                if ((string) ($value['metadata']['phone_number_id'] ?? '') !== (string) $connection->phone_number_id) {
                    continue;
                }

                foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $status) {
                    if (! is_array($status) || ! is_string($status['id'] ?? null) || ! is_string($status['status'] ?? null)) {
                        continue;
                    }

                    $error = is_array($status['errors'][0] ?? null) ? $status['errors'][0] : null;

                    $statuses[] = [
                        'id' => mb_substr($status['id'], 0, 100),
                        'status' => strtolower(mb_substr($status['status'], 0, 20)),
                        'timestamp' => is_numeric($status['timestamp'] ?? null) ? (int) $status['timestamp'] : null,
                        'error' => $error !== null ? mb_substr(trim('(#'.($error['code'] ?? '?').') '.($error['title'] ?? $error['message'] ?? 'Not delivered')), 0, 300) : null,
                    ];
                }
            }
        }

        return $statuses;
    }
}
