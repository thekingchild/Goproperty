<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Audit\AccessLog;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * First step of the customer sign-in pipeline (UX_DESIGN.md § 3.1): refuses
 * paused identifiers, requires the bot check when due, and turns every
 * failure into the one generic message, so a reply never shows whether an
 * account exists.
 */
final readonly class GuardSignIn
{
    public const string FAILED = "Those details don't match our records.";

    public const string FAILED_CHECK = "Those details don't match our records. Please complete the security check and try again.";

    public const string CHECK_FAILED = 'Please complete the security check and try again.';

    /** Session flash: the form shows the bot check on the next render. */
    public const string SHOW_CHECK = 'sign-in.check';

    /** Session flash: seconds left of a pause, for the locked state. */
    public const string LOCKED = 'sign-in.locked';

    public function __construct(
        private SignInSecurity $security,
        private Turnstile $turnstile,
        private FailedAttempt $attempt,
        private AccessLog $accessLog,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $typed = Identifier::from($request->input('identifier'));
        $identifier = $typed->forLimits();
        $ip = $request->ip();

        if ($this->security->isLocked($identifier, $ip)) {
            return $this->locked($request, $identifier, $ip);
        }

        if ($this->security->needsCheck($identifier, $ip) && ! $this->turnstile->verify($request->input('cf-turnstile-response'), $ip)) {
            $request->session()->flash(self::SHOW_CHECK, true);
            $this->accessLog->record('turnstile_failed', 'user', $identifier->accountId(), $typed->value);

            return $this->refuse($request, self::CHECK_FAILED);
        }

        // Fortify's pipeline turns a failed attempt into a redirect, so the
        // failure is read from its Failed event (see FortifyServiceProvider).
        $this->attempt->failed = false;
        $response = $next($request);

        if (! $this->attempt->failed) {
            $this->security->clear($identifier, $ip);

            return $response;
        }

        $this->security->recordFailure($identifier, $ip);

        if ($this->security->isLocked($identifier, $ip)) {
            $this->accessLog->record('lockout', 'user', $identifier->accountId(), $typed->value);

            return $this->locked($request, $identifier, $ip);
        }

        $check = $this->security->needsCheck($identifier, $ip);

        if ($check) {
            $request->session()->flash(self::SHOW_CHECK, true);
        }

        return $this->refuse($request, $check ? self::FAILED_CHECK : self::FAILED);
    }

    private function locked(Request $request, Identifier $identifier, ?string $ip): RedirectResponse
    {
        $request->session()->flash(self::LOCKED, $this->security->lockedFor($identifier, $ip));

        return $this->refuse($request, sprintf('For your security, sign-in is paused for %d minutes.', $this->security->lockoutMinutes()));
    }

    /** Back to the form with one message on the identifier field, and nothing else. */
    private function refuse(Request $request, string $message): RedirectResponse
    {
        $request->session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['identifier' => [$message]])));
        $request->session()->flashInput($request->only('identifier', 'remember'));

        return redirect()->route('login');
    }
}
