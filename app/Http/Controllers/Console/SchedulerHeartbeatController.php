<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Hosting\Heartbeats;
use App\Installer\Health\InstallationHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * When the scheduler and the queue tick last ran (PLATFORM_CONSOLE.md § 2,
 * Scheduler row), for signed-in staff with `system.status`. The installer's
 * finish screen polls it for its live cron check (INSTALLER.md § 5).
 */
final class SchedulerHeartbeatController
{
    public function __invoke(Heartbeats $heartbeats): JsonResponse
    {
        Gate::authorize('system.status');

        return response()->json([
            'scheduler' => self::beat($heartbeats->lastAt(Heartbeats::SCHEDULER)),
            'queue_tick' => self::beat($heartbeats->lastAt(Heartbeats::QUEUE_TICK)),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array{at: string|null, fresh: bool} */
    private static function beat(?CarbonImmutable $at): array
    {
        return [
            'at' => $at?->toIso8601String(),
            'fresh' => $at !== null && $at->diffInMinutes(now(), true) <= InstallationHealth::HEARTBEAT_MINUTES,
        ];
    }
}
