<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Operations\Alerts\Watchdog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After every web response is sent (PHP-FPM and LiteSpeed flush it before
 * `terminate`), the watchdog checks that the scheduler still runs: a
 * stopped scheduler cannot report itself (PLATFORM_CONSOLE.md § 2 "Who
 * notices a stopped scheduler"). One cache read while the heartbeat is
 * fresh; nothing on the response.
 */
final readonly class WatchSchedulerHeartbeat
{
    public function __construct(private Watchdog $watchdog) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->watchdog->afterRequest($request->routeIs('operations.uptime') ? 'uptime' : 'web');
    }
}
