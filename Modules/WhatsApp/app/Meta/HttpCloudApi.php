<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\WhatsApp\Models\WhatsAppConnection;

/**
 * Meta's Graph API for WhatsApp (graph.facebook.com, the version in
 * `services.whatsapp.graph_version`) with the organization's own token as
 * a Bearer header. Errors keep Meta's own words (code and message), never
 * the token.
 */
final readonly class HttpCloudApi implements CloudApi
{
    /** At most this many pages of templates (100 each). */
    private const int TEMPLATE_PAGES = 20;

    public function __construct(
        private string $baseUrl,
        private string $version,
        private int $timeout = 15,
    ) {}

    public function name(): string
    {
        return 'meta';
    }

    public function check(WhatsAppConnection $connection): ConnectionCheck
    {
        try {
            $response = $this->request($connection)->get('/'.rawurlencode((string) $connection->phone_number_id), ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException) {
            return new ConnectionCheck(CheckResult::Unavailable, error: 'Meta could not be reached.');
        }

        if ($response->clientError()) {
            return new ConnectionCheck(CheckResult::Refused, error: self::error($response));
        }

        if (! $response->successful() || ! is_string($response->json('display_phone_number'))) {
            return new ConnectionCheck(CheckResult::Unavailable, error: 'Meta answered '.$response->status().'.');
        }

        $name = $response->json('verified_name');

        return new ConnectionCheck(CheckResult::Accepted, mb_substr((string) $response->json('display_phone_number'), 0, 32), is_string($name) ? mb_substr($name, 0, 120) : null);
    }

    public function templates(WhatsAppConnection $connection): array
    {
        $templates = [];
        $after = null;

        for ($page = 0; $page < self::TEMPLATE_PAGES; $page++) {
            $query = ['fields' => 'id,name,language,category,status,components', 'limit' => 100];

            if ($after !== null) {
                $query['after'] = $after;
            }

            try {
                $response = $this->request($connection)->get('/'.rawurlencode((string) $connection->waba_id).'/message_templates', $query);
            } catch (ConnectionException) {
                throw new MetaUnavailable('Meta could not be reached.');
            }

            $this->throwUnlessSuccessful($response);
            $data = $response->json('data');

            if (! is_array($data)) {
                throw new MetaUnavailable('Meta answered without a list of templates.');
            }

            foreach ($data as $template) {
                if (is_array($template)) {
                    $templates[] = $template;
                }
            }

            $after = $response->json('paging.cursors.after');

            if (! is_string($after) || $after === '' || ! is_string($response->json('paging.next'))) {
                break;
            }
        }

        return $templates;
    }

    public function send(WhatsAppConnection $connection, string $to, string $template, string $language, array $components): string
    {
        try {
            $response = $this->request($connection)->post('/'.rawurlencode((string) $connection->phone_number_id).'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $language],
                    'components' => $components,
                ],
            ]);
        } catch (ConnectionException) {
            throw new MetaUnreachable('The call to Meta broke off before it answered.');
        }

        $this->throwUnlessSuccessful($response);
        $id = $response->json('messages.0.id');

        if (! is_string($id) || $id === '') {
            throw new MetaUnavailable('Meta answered without a message id.');
        }

        return mb_substr($id, 0, 100);
    }

    private function request(WhatsAppConnection $connection): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/'.trim($this->version, '/'))
            ->withToken((string) $connection->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);
    }

    /** @throws MetaRefused|MetaUnavailable */
    private function throwUnlessSuccessful(Response $response): void
    {
        if ($response->clientError()) {
            $code = $response->json('error.code');

            // 190: the access token expired or was revoked; 401: not accepted at all.
            throw new MetaRefused(self::error($response), $response->status() === 401 || $code === 190);
        }

        if (! $response->successful()) {
            throw new MetaUnavailable('Meta answered '.$response->status().'.');
        }
    }

    /** "(#131026) Message undeliverable": Meta's code and words, cut short. */
    private static function error(Response $response): string
    {
        $message = $response->json('error.message');
        $code = $response->json('error.code');
        $text = is_string($message) && $message !== '' ? $message : 'Meta refused the request (HTTP '.$response->status().').';

        return mb_substr((is_int($code) ? '(#'.$code.') ' : '').$text, 0, 300);
    }
}
