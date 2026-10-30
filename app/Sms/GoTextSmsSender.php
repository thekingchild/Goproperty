<?php

declare(strict_types=1);

namespace App\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GoText (app.gotext.ng; docs/plans/sms-provider-2026-10-29.md), the HTTP
 * API as SocietyFlow uses it: `POST /api/http/sms/send` with a JSON body
 * (`api_token`, `recipient` without the `+`, `sender_id`, `type` plain,
 * `message`) — never the GET form, which puts the token and the number in
 * the URL — and `GET /api/http/sms/{uid}` with the token in the body.
 *
 * GoText answers `{"status": "success", "data": …}` or `{"status":
 * "error", "message": …}`. What `data` holds is still to be confirmed with
 * GoText, so the `uid`, status word, pages and cost are looked for by name
 * anywhere in it and left empty when absent.
 */
final readonly class GoTextSmsSender implements SmsSender
{
    public function __construct(
        private string $token,
        private string $senderId,
        private string $baseUrl,
    ) {}

    public function name(): string
    {
        return 'gotext';
    }

    public function send(string $to, string $text): SmsReceipt
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->post($this->url('/api/http/sms/send'), [
            'api_token' => $this->token,
            'recipient' => ltrim($to, '+'),
            'sender_id' => $this->senderId,
            'type' => 'plain',
            'message' => $text,
        ]));

        $data = $response->json('data');

        return new SmsReceipt(
            messageId: self::find($data, ['uid', 'message_id', 'id']),
            providerStatus: self::find($data, ['status']),
            pages: self::number(self::find($data, ['sms_count', 'pages', 'parts', 'units'])),
            costKobo: self::kobo(self::find($data, ['cost', 'price', 'amount'])),
        );
    }

    public function status(string $messageId): SmsReport
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->send('GET', $this->url('/api/http/sms/'.rawurlencode($messageId)), [
            'json' => ['api_token' => $this->token],
        ]));

        $word = self::find($response->json('data'), ['status', 'delivery_status']);

        return new SmsReport(self::statusOf($word), $word);
    }

    /** GoText's own word for a message's state, as a delivery status (null: not final yet). */
    public static function statusOf(?string $word): ?DeliveryStatus
    {
        $word = strtolower(trim((string) $word));

        return match (true) {
            $word === '' => null,
            str_contains($word, 'undeliver'), str_contains($word, 'fail'), str_contains($word, 'reject'),
            str_contains($word, 'expire'), str_contains($word, 'error'), $word === 'dnd' => DeliveryStatus::Undelivered,
            str_contains($word, 'deliver') => DeliveryStatus::Delivered,
            default => null,
        };
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws SmsFailed
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request(Http::acceptJson()->asJson()->timeout(15)->connectTimeout(5));
        } catch (ConnectionException) {
            // A timeout too: GoText may have taken the message (P05-03 keeps its credit).
            throw SmsFailed::unreachable('GoText could not be reached.');
        }

        if (! $response->successful() || $response->json('status') !== 'success') {
            $message = $response->json('message');

            throw new SmsFailed('GoText refused the request (HTTP '.$response->status().(is_string($message) && $message !== '' ? ': '.mb_substr($message, 0, 200) : '').').');
        }

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /** @param  list<string>  $keys */
    private static function find(mixed $data, array $keys): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($keys as $key) {
            if (isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key]) || is_float($data[$key])) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        foreach ($data as $value) {
            if (is_array($value) && ($found = self::find($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function number(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** A naira amount as kobo (GoText's cost is in naira, to be confirmed). */
    private static function kobo(?string $value): ?int
    {
        if ($value === null || preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        [$naira, $kobo] = array_pad(explode('.', $value), 2, '0');

        return (int) $naira * 100 + (int) str_pad($kobo, 2, '0');
    }
}
