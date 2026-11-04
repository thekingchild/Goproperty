<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Hosting\Heartbeats;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\Checks\SchedulerStoppedCheck;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The uptime link for an outside monitor (PLATFORM_CONSOLE.md § 2 "Who
 * notices a stopped scheduler"): `GET /up/scheduler/{token}` answers 200
 * while the scheduler runs and 503 once its heartbeat is older than the
 * alert window, so a free uptime service can page on the 503 even when this
 * server cannot send anything. The request also wakes the watchdog
 * (WatchSchedulerHeartbeat), which raises the console's own alert. The
 * token is made in Settings → Operations alerts, shown once and kept only
 * as its SHA-256; a wrong or unset token is a plain 404. No session, no
 * cookies; it tells nothing but "running" or "stopped" and the last run.
 */
final class UptimeCheckController
{
    public function __invoke(string $token, Heartbeats $heartbeats): JsonResponse
    {
        $settings = AlertSettings::current();

        abort_if($settings->uptimeTokenHash === null || ! hash_equals($settings->uptimeTokenHash, hash('sha256', $token)), 404);

        $at = $heartbeats->lastAt(Heartbeats::SCHEDULER);
        $running = $at !== null && ! SchedulerStoppedCheck::stale($at, CarbonImmutable::now(), $settings);

        return response()->json(['scheduler' => $running ? 'running' : 'stopped', 'last_run_at' => $at?->toIso8601String()], $running ? 200 : 503)
            ->header('Cache-Control', 'no-store, private');
    }
}
