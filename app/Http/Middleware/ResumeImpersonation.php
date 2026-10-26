<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use App\Models\ImpersonationSession;
use App\Platform\Console\ConsoleRequests;
use App\Platform\Impersonation\ImpersonationAudit;
use App\Platform\Impersonation\ImpersonationEndReason;
use App\Platform\Impersonation\Impersonations;
use App\Tenancy\CurrentImpersonation;
use App\Tenancy\ImpersonationBlocked;
use App\Tenancy\ImpersonationGuard;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The customer side of a GoProperty support session (PLATFORM_CONSOLE.md
 * § 6). Runs in the `web` group after the session starts and before any
 * `auth` middleware. When the customer session holds a support session
 * (put there by the one-time hand-off link), every request:
 *
 * - re-reads the session row: past its 30 minutes it ends (`timeout`), if
 *   ended or no longer allowed the customer-side session is wiped and the
 *   browser goes back to the console;
 * - acts as the user for this request only (Auth::setUser: no sign-in, no
 *   "remember me", nothing stored that outlives the session row), with the
 *   staff member as impersonator on every audit entry (AuditContext);
 * - applies ImpersonationGuard to the request (sign-in details, blocked
 *   actions, read-only mode); signing out ends the session;
 * - writes the page viewed or the action taken (and any refusal) to the
 *   audit log, and shows the support banner on every HTML page.
 *
 * Console requests never carry a support session (they use the staff
 * cookie), so they pass straight through.
 */
final class ResumeImpersonation
{
    public function __construct(
        private readonly Impersonations $impersonations,
        private readonly CurrentImpersonation $current,
        private readonly ImpersonationGuard $guard,
        private readonly ImpersonationAudit $audit,
        private readonly AuditContext $auditContext,
        private readonly ConsoleRequests $console,
        private readonly ExceptionHandler $exceptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $this->console->isConsoleRequest($request)) {
            return $next($request);
        }

        $id = $request->session()->get(CurrentImpersonation::SESSION_KEY);

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return $next($request);
        }

        $session = $this->impersonations->resume((int) $id);

        if ($session === null) {
            return $this->leave($request, (int) $id, 'The GoProperty support session has ended.');
        }

        $this->current->begin($session);
        $this->auditContext->impersonatedBy($session->staff_id, $session->id);
        Auth::guard('web')->setUser($session->user);

        // Signing out of the customer app ends the support session.
        if ($request->routeIs('logout')) {
            $this->impersonations->end($session, ImpersonationEndReason::Manual, extra: ['via' => 'sign_out']);

            return $this->leave($request, $session->id);
        }

        try {
            $this->guard->ensureRequestAllowed($request);
            $response = $next($request);
        } catch (ImpersonationBlocked $blocked) {
            // Rendered here so the refusal page keeps the banner (and its "End session").
            $response = $this->exceptions->render($request, $blocked);
        }

        $this->record($request, $response, $session);

        return $this->withBanner($response, $session);
    }

    /**
     * Wipes the customer-side session (the impersonation is gone with it)
     * and sends the browser back to the console.
     */
    private function leave(Request $request, int $sessionId, ?string $message = null): Response
    {
        Auth::guard('web')->forgetUser();
        $this->current->clear();
        $this->auditContext->impersonatedBy(null);
        $request->session()->invalidate();

        if ($message !== null && ($request->expectsJson() || $this->guard->isLivewireUpdate($request))) {
            return new JsonResponse(['message' => $message], 403);
        }

        return new RedirectResponse(self::consoleUrl(ImpersonationSession::query()->find($sessionId)));
    }

    /**
     * Where a session returns to when it ends: the user's page in the
     * console (every role that may impersonate can open it), else the
     * console's home, else the site (console switched off).
     */
    public static function consoleUrl(?ImpersonationSession $session): string
    {
        try {
            return $session !== null
                ? route('filament.console.resources.customer-users.view', ['record' => $session->user_id])
                : route('filament.console.pages.dashboard');
        } catch (Throwable) {
            return url('/');
        }
    }

    private function record(Request $request, Response $response, ImpersonationSession $session): void
    {
        if ($this->current->refusalRecorded()) {
            return;
        }

        $route = $request->route();
        $properties = [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $route instanceof Route ? $route->getName() : null,
            'status' => $response->getStatusCode(),
        ];

        if ($response->getStatusCode() === 403) {
            $message = $response instanceof JsonResponse ? ($response->getData(true)['message'] ?? null) : null;
            $this->audit->record($session, 'impersonation.blocked', 'Refused: '.$properties['method'].' '.$properties['path'], array_filter($properties + [
                'message' => is_string($message) ? $message : null,
            ], static fn (mixed $value): bool => $value !== null));

            return;
        }

        if ($this->guard->isLivewireUpdate($request)) {
            $calls = $this->livewireCalls($request);

            // Typing in a field or paging is not an action; method calls are.
            if ($calls !== []) {
                $this->audit->record($session, 'impersonation.action', 'Action: '.implode(', ', array_map(static fn (array $call): string => $call['component'].'::'.$call['method'], $calls)), $properties + ['calls' => $calls]);
            }

            return;
        }

        if ($request->isMethodSafe()) {
            $this->audit->record($session, 'impersonation.page_viewed', 'Viewed '.$properties['path'], $properties);

            return;
        }

        if (! $request->routeIs('impersonation.end')) {
            $this->audit->record($session, 'impersonation.action', 'Action: '.$properties['method'].' '.$properties['path'], $properties);
        }
    }

    /**
     * The component methods a Livewire update request called (names only:
     * parameters can hold personal data).
     *
     * @return list<array{component: string, method: string}>
     */
    private function livewireCalls(Request $request): array
    {
        $calls = [];
        $components = $request->input('components');

        foreach (is_array($components) ? $components : [] as $component) {
            if (! is_array($component) || ! is_array($component['calls'] ?? null)) {
                continue;
            }

            $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
            $name = is_array($snapshot) && is_string($snapshot['memo']['name'] ?? null) ? $snapshot['memo']['name'] : 'unknown';

            foreach ($component['calls'] as $call) {
                $method = is_array($call) && is_string($call['method'] ?? null) ? $call['method'] : null;

                if ($method !== null && ! in_array($method, ['$refresh', '$sync', '$commit'], true)) {
                    $calls[] = ['component' => mb_substr($name, 0, 120), 'method' => mb_substr($method, 0, 120)];
                }
            }
        }

        return $calls;
    }

    /** The banner on every HTML page: who is viewing, the mode, and "End session". */
    private function withBanner(Response $response, ImpersonationSession $session): Response
    {
        if (! $response instanceof HttpResponse
            || ! in_array($response->getStatusCode(), [200, 403], true)
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $content = (string) $response->getContent();

        if (preg_match('/<body\b[^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $response;
        }

        $banner = view('impersonation.banner', ['session' => $session])->render();
        $at = $match[0][1] + strlen($match[0][0]);

        $response->setContent(substr($content, 0, $at).$banner.substr($content, $at));

        return $response;
    }
}
