<?php

declare(strict_types=1);

namespace App\Hosting;

use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Finds driver settings that cannot work together, so a move between
 * hosting profiles fails at deploy time rather than in production.
 */
final readonly class HostingCheck
{
    public function __construct(private Config $config) {}

    /** @return list<string> */
    public function problems(): array
    {
        $problems = [];

        try {
            $profile = HostingProfile::current();
        } catch (Throwable $e) {
            $problems[] = $e->getMessage();
            $profile = null;
        }

        $redisUsers = array_keys(array_filter([
            'QUEUE_CONNECTION' => $this->queueDriver() === 'redis',
            'CACHE_STORE' => $this->cacheDriver() === 'redis',
            'SESSION_DRIVER' => $this->string('session.driver') === 'redis',
        ]));

        if ($redisUsers !== [] && ! extension_loaded('redis') && ! class_exists('Predis\Client')) {
            $problems[] = sprintf('%s use Redis, but neither the phpredis extension nor predis/predis is installed.', implode(', ', $redisUsers));
        }

        $broadcast = $this->string('broadcasting.default');

        if ($broadcast === 'reverb' && ! class_exists('Pusher\Pusher')) {
            $problems[] = 'BROADCAST_CONNECTION=reverb needs pusher/pusher-php-server (installed with Reverb).';
        }

        try {
            $realtime = Realtime::fromConfig($this->config);

            if ($realtime->usesWebsockets() && $broadcast !== 'reverb') {
                $problems[] = 'REALTIME_DRIVER=reverb needs BROADCAST_CONNECTION=reverb.';
            }
        } catch (Throwable $e) {
            $problems[] = $e->getMessage();
        }

        $pdf = $this->string('pdf.driver');

        if (! in_array($pdf, ['dompdf', 'chrome'], true)) {
            $problems[] = sprintf('PDF_DRIVER must be "dompdf" or "chrome", got "%s".', $pdf);
        } elseif ($pdf === 'chrome' && $this->string('pdf.chrome.url') === '') {
            $problems[] = 'PDF_DRIVER=chrome needs PDF_CHROME_URL (the Gotenberg service).';
        }

        if ($this->tickEnabled()) {
            $tickConnection = $this->string('hosting.queue_tick.connection');

            if ($this->string("queue.connections.{$tickConnection}.driver") !== 'database') {
                $problems[] = 'The queue tick drains a "database" connection only; check QUEUE_TICK_CONNECTION.';
            } elseif ($this->string('queue.default') !== $tickConnection) {
                $problems[] = sprintf('Jobs are pushed to "%s" but the queue tick drains "%s".', $this->string('queue.default'), $tickConnection);
            }
        } elseif ($profile === HostingProfile::Shared) {
            $problems[] = 'QUEUE_TICK_ENABLED is off on shared hosting, so nothing will run queued jobs.';
        }

        return $problems;
    }

    public function tickEnabled(): bool
    {
        return (bool) $this->config->get('hosting.queue_tick.enabled');
    }

    public function queueDriver(): string
    {
        return $this->string('queue.connections.'.$this->string('queue.default').'.driver');
    }

    public function cacheDriver(): string
    {
        return $this->string('cache.stores.'.$this->string('cache.default').'.driver');
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
