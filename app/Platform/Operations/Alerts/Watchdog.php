<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use App\Hosting\Heartbeats;
use App\Installer\ApplicationKey;
use App\Installer\InstallationMarkers;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Who notices a stopped scheduler (PLATFORM_CONSOLE.md § 2 "Who notices a
 * stopped scheduler"): not the scheduler, and on shared hosting not the
 * queue either (its tick runs inside the scheduler). So every web request,
 * once its response is sent, reads the scheduler's heartbeat (one cache
 * read); only when it is stale does it run the scheduler check, at most
 * once a minute across all requests. The uptime link (/up/scheduler/{token})
 * is a web request too, so an outside monitor pinging it every few minutes
 * covers a site nobody visits at night, and itself alerts on its 503.
 *
 * Never lets a problem reach the request: a database or cache that is down
 * simply means nothing is checked.
 */
final readonly class Watchdog
{
    /** Below the smallest window allowed (3 minutes): no need to read the settings. */
    private const int FRESH_SECONDS = 150;

    private const string THROTTLE = 'operations:watchdog';

    public function __construct(private Heartbeats $heartbeats, private Cache $cache, private AlertMonitor $monitor, private InstallationMarkers $markers) {}

    /** @param  'web'|'uptime'  $source */
    public function afterRequest(string $source = 'web', ?CarbonImmutable $now = null): void
    {
        // A copy still being set up (no key, or no install lock file yet) may
        // have no database: such requests touch nothing (INSTALLER.md § 3).
        if (! ApplicationKey::configured() || ! $this->markers->lockExists()) {
            return;
        }

        try {
            $now ??= CarbonImmutable::now();
            $at = $this->heartbeats->lastAt(Heartbeats::SCHEDULER);

            if ($at === null || $at->diffInSeconds($now, true) <= self::FRESH_SECONDS) {
                return;
            }

            if (! $this->cache->add(self::THROTTLE, $now->getTimestamp(), 55)) {
                return;
            }

            $this->monitor->run($source, [AlertRule::SchedulerStopped], $now);
        } catch (Throwable) {
            // The request is done; the next one tries again.
        }
    }
}
