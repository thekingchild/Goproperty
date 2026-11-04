<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
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

            // Back to the page after signing in again: $request is the page's
            // even on a Livewire update (Livewire replays its route).
            $session->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

            return redirect()->to(Filament::getLoginUrl())
                ->with('console.timed-out', true);
        }

        // Page loads and the idle warning's ping count as activity; a
        // Livewire update (a widget's poll as much as a click) is only
        // checked, so an open dashboard never keeps a session alive.
        if (! Livewire::isLivewireRequest()) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());
        }

        return $next($request);
    }
}
