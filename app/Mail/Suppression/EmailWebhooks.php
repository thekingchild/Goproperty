<?php

declare(strict_types=1);

namespace App\Mail\Suppression;

/**
 * Reads the email provider's bounce and complaint webhooks (P05-04;
 * PLATFORM_CONSOLE.md § 5 "Delivery") into suppressions. Only permanent
 * failures and spam complaints suppress; soft bounces, deliveries and
 * opens are kept and ignored.
 *
 * Proof: the URL carries a secret token (`MAIL_WEBHOOK_TOKEN`, compared in
 * constant time) for every provider; Mailgun's calls must also carry its
 * HMAC-SHA256 signature when the signing key is set
 * (`MAILGUN_WEBHOOK_SIGNING_KEY`). Postmark: set the URL with the token as
 * the Bounce and Spam Complaint webhook. Mailgun: the "Permanent failure"
 * and "Spam complaint" webhooks.
 */
final readonly class EmailWebhooks
{
    public const int MAX_BODY_BYTES = 262144;

    /** Postmark bounce types that mean the address is gone. */
    private const array POSTMARK_PERMANENT = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'SpamNotification', 'Blocked'];

    public function __construct(private Suppressions $suppressions) {}

    public function tokenMatches(string $token): bool
    {
        $expected = config('mail.webhooks.token');

        return is_string($expected) && strlen($expected) >= 24 && hash_equals($expected, $token);
    }

    public function receive(string $provider, string $body, ?string $ip): EmailWebhookDelivery
    {
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];

        return EmailWebhookDelivery::query()->forceCreate([
            'organization_id' => null,
            'provider' => $provider,
            'event' => mb_substr(self::eventName($provider, $data), 0, 60) ?: null,
            'payload' => $body,
            'signature_valid' => $this->proved($provider, $data),
            'ip_address' => $ip,
            'received_at' => now(),
        ]);
    }

    /** Suppresses what the delivery reports; returns how many addresses were newly suppressed. */
    public function process(EmailWebhookDelivery $delivery): int
    {
        $data = json_decode($delivery->payload, true);
        $events = is_array($data) ? $this->events($delivery->provider, $data) : [];
        $added = 0;

        foreach ($events as $event) {
            $added += $this->suppressions->suppress($event->email, $event->reason, $delivery->provider, $event->detail) ? 1 : 0;
        }

        $delivery->forceFill([
            'processed_at' => now(),
            'outcome' => $events === [] ? 'ignored' : ($added > 0 ? 'suppressed' : 'duplicate'),
        ])->save();

        return $added;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<ProviderEvent>
     */
    public function events(string $provider, array $data): array
    {
        return match ($provider) {
            'postmark' => self::postmark($data),
            'mailgun' => self::mailgun($data),
            default => [],
        };
    }

    /** @param  array<array-key, mixed>  $data */
    private function proved(string $provider, array $data): bool
    {
        if ($provider !== 'mailgun') {
            return true; // the URL token is the proof
        }

        $key = config('services.mailgun.webhook_signing_key');

        if (! is_string($key) || $key === '') {
            return true;
        }

        $signature = is_array($data['signature'] ?? null) ? $data['signature'] : [];
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');
        $given = (string) ($signature['signature'] ?? '');

        return $timestamp !== '' && $token !== '' && $given !== ''
            && abs(time() - (int) $timestamp) <= 900
            && hash_equals(hash_hmac('sha256', $timestamp.$token, $key), $given);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<ProviderEvent>
     */
    private static function postmark(array $data): array
    {
        $email = is_string($data['Email'] ?? null) ? $data['Email'] : null;
        $type = (string) ($data['Type'] ?? '');
        $record = (string) ($data['RecordType'] ?? '');
        $detail = is_string($data['Description'] ?? null) ? $data['Description'] : null;

        if ($email === null) {
            return [];
        }

        if ($record === 'SpamComplaint' || $type === 'SpamComplaint') {
            return [new ProviderEvent($email, SuppressionReason::Complaint, $detail)];
        }

        if ($record === 'Bounce' && in_array($type, self::POSTMARK_PERMANENT, true)) {
            return [new ProviderEvent($email, $type === 'SpamNotification' ? SuppressionReason::Complaint : SuppressionReason::Bounce, $detail ?? $type)];
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<ProviderEvent>
     */
    private static function mailgun(array $data): array
    {
        $event = is_array($data['event-data'] ?? null) ? $data['event-data'] : [];
        $email = is_string($event['recipient'] ?? null) ? $event['recipient'] : null;
        $status = is_array($event['delivery-status'] ?? null) ? $event['delivery-status'] : [];
        $detail = is_string($status['description'] ?? null) && $status['description'] !== '' ? $status['description'] : (is_string($status['message'] ?? null) ? $status['message'] : null);

        if ($email === null) {
            return [];
        }

        return match (true) {
            ($event['event'] ?? null) === 'complained' => [new ProviderEvent($email, SuppressionReason::Complaint, $detail)],
            ($event['event'] ?? null) === 'failed' && ($event['severity'] ?? null) === 'permanent' => [new ProviderEvent($email, SuppressionReason::Bounce, $detail)],
            default => [],
        };
    }

    /** @param  array<array-key, mixed>  $data */
    private static function eventName(string $provider, array $data): string
    {
        return match ($provider) {
            'postmark' => trim((string) ($data['RecordType'] ?? '').' '.(string) ($data['Type'] ?? '')),
            'mailgun' => (string) (is_array($data['event-data'] ?? null) ? ($data['event-data']['event'] ?? '') : ''),
            default => '',
        };
    }
}
