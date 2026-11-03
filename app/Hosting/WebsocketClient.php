<?php

declare(strict_types=1);

namespace App\Hosting;

use Illuminate\Contracts\Config\Repository;

/**
 * What a live screen's browser needs to listen to Reverb (VPS profile,
 * ARCHITECTURE.md § 12.1): the app's public key and where to connect, never
 * the secret. Null on shared hosting, where screens only poll.
 */
final readonly class WebsocketClient
{
    public function __construct(private Repository $config, private Realtime $realtime) {}

    /**
     * Settings for resources/js/realtime.js; $channel is the private channel's
     * name without its `private-` prefix.
     *
     * @return array{channel: string, key: string, host: string, port: int, scheme: string, authEndpoint: string}|null
     */
    public function settings(string $channel): ?array
    {
        if (! $this->realtime->usesWebsockets()) {
            return null;
        }

        $key = $this->config->get('broadcasting.connections.reverb.key');
        $host = $this->config->get('hosting.realtime.client.host');
        $port = $this->config->get('hosting.realtime.client.port');
        $scheme = $this->config->get('hosting.realtime.client.scheme');

        if (! is_string($key) || $key === '' || ! is_string($host) || $host === '') {
            // goproperty:hosting reports the missing settings; the screen keeps polling.
            return null;
        }

        return [
            'channel' => $channel,
            'key' => $key,
            'host' => $host,
            'port' => is_numeric($port) ? (int) $port : 443,
            'scheme' => $scheme === 'http' ? 'http' : 'https',
            'authEndpoint' => url('/broadcasting/auth'),
        ];
    }
}
