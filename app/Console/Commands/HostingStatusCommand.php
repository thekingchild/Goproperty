<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Hosting\Heartbeats;
use App\Hosting\HostingCheck;
use App\Hosting\HostingProfile;
use App\Hosting\Realtime;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Shows the active hosting profile and drivers, whether `exec`/`proc_open`
 * exist, and the background-work heartbeats. Exits non-zero when the
 * settings cannot work together, so deploys run it before switching
 * releases (docs/runbooks/shared-hosting.md § 3, § 5).
 */
final class HostingStatusCommand extends Command
{
    protected $signature = 'goproperty:hosting';

    protected $description = 'Show the hosting profile, drivers and background-work heartbeats';

    public function handle(Config $config, HostingCheck $check, Heartbeats $heartbeats): int
    {
        try {
            $profile = HostingProfile::current()->label();
        } catch (Throwable) {
            $profile = 'invalid';
        }

        try {
            $realtime = Realtime::fromConfig($config);
            $realtimeLabel = $realtime->usesWebsockets()
                ? "Reverb (fallback poll {$realtime->pollSeconds()}s)"
                : "Polling every {$realtime->pollSeconds()}s";
        } catch (Throwable) {
            $realtimeLabel = 'invalid';
        }

        $this->table(['Setting', 'Value'], [
            ['Profile', $profile],
            ['Queue', $this->value($config, 'queue.default')." ({$check->queueDriver()})"],
            ['Cache', $this->value($config, 'cache.default')." ({$check->cacheDriver()})"],
            ['Session', $this->value($config, 'session.driver')],
            ['Broadcast', $this->value($config, 'broadcasting.default')],
            ['Realtime', $realtimeLabel],
            ['PDF', $this->value($config, 'pdf.driver')],
            ['Queue tick', $check->tickEnabled() ? 'on, draining "'.$this->value($config, 'hosting.queue_tick.connection').'"' : 'off'],
            ['exec / proc_open (CLI)', $this->available('exec').' / '.$this->available('proc_open')],
            ...$this->heartbeatRows($heartbeats),
        ]);

        $problems = $check->problems();

        if ($problems === []) {
            $this->components->info('Hosting configuration is consistent.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }

    private function value(Config $config, string $key): string
    {
        $value = $config->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    private function available(string $function): string
    {
        return function_exists($function) ? 'available' : 'disabled';
    }

    /**
     * Heartbeats live in the cache, which may not exist yet (deploy checks
     * run before migrations), so a failed read is shown, not thrown.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function heartbeatRows(Heartbeats $heartbeats): array
    {
        try {
            return [
                ['Scheduler heartbeat', $this->ago($heartbeats, Heartbeats::SCHEDULER)],
                ['Last queue tick', $this->tick($heartbeats)],
            ];
        } catch (Throwable) {
            return [
                ['Scheduler heartbeat', 'unavailable (cache not readable)'],
                ['Last queue tick', 'unavailable (cache not readable)'],
            ];
        }
    }

    private function ago(Heartbeats $heartbeats, string $name): string
    {
        return $heartbeats->lastAt($name)?->diffForHumans() ?? 'never';
    }

    private function tick(Heartbeats $heartbeats): string
    {
        $last = $heartbeats->last(Heartbeats::QUEUE_TICK);

        if ($last === null) {
            return 'never';
        }

        return sprintf(
            '%s: %s jobs, stopped by %s',
            $this->ago($heartbeats, Heartbeats::QUEUE_TICK),
            is_int($last['total'] ?? null) ? $last['total'] : '?',
            is_string($last['stopped_by'] ?? null) ? $last['stopped_by'] : '?',
        );
    }
}
