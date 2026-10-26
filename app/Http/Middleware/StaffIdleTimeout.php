<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff sessions end after 30 minutes without a request (UX_DESIGN.md
 * § 3.2). The console page warns two minutes before (see
 * console/idle-warning) and "Stay signed in" pings the server.
 */
final class StaffIdleTimeout
{
    public const int MINUTES = 30;

    public const int WARN_SECONDS_BEFORE = 120;

    public const string SESSION_KEY = 'console.last_activity';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $last = $session->get(self::SESSION_KEY);

        if (is_int($last) && now()->getTimestamp() - $last > self::MINUTES * 60) {
            Filament::auth()->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->guest(Filament::getLoginUrl())
                ->with('console.timed-out', true);
        }

        $session->put(self::SESSION_KEY, now()->getTimestamp());

        return $next($request);
    }
}
