<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\EnvironmentFile;
use App\Installer\InstallationSteps;
use App\Installer\SetupToken;
use App\Installer\Web\InstallerGrant;
use App\Installer\Web\InstallerSession;
use App\Installer\Web\UnlockAttempts;
use App\Installer\Web\UnlockChallenge;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unlocking (INSTALLER.md § 4), by setup token (SSH) or by file check
 * (File Manager). These posts run before APP_KEY exists, so without the
 * session, cookie and CSRF middleware; instead they must come from this
 * site's own page (same-origin check), over HTTPS, and at most 5 times per
 * IP address in 15 minutes.
 *
 * On success: `.env` from `.env.example` if missing, APP_KEY generated (or
 * an existing valid one kept) and switched into this request, debug pages
 * off, then the encrypted file session starts and receives the grant.
 */
final class UnlockController
{
    public function __construct(
        private readonly Container $app,
        private readonly InstallerController $screen,
        private readonly UnlockAttempts $attempts,
        private readonly InstallationSteps $steps,
        private readonly InstallerSession $session,
        private readonly InstallerGrant $grant,
    ) {}

    public function token(Request $request, SetupToken $token): Response
    {
        $value = $request->input('token');

        return $this->unlock($request, 'setup token', fn (): bool => is_string($value) && $token->verify(trim($value)),
            'That setup token is not right. Run php artisan goproperty:setup-token again and paste the new token.');
    }

    public function file(Request $request, UnlockChallenge $challenge): Response
    {
        return $this->unlock($request, 'file check', fn (): bool => $challenge->verifyFile(), $challenge->fileExists()
            ? 'storage/app/install-unlock.txt does not hold a phrase from the last '.$challenge->minutes().' minutes. Copy the new phrase below into it and try again.'
            : 'The installer cannot find storage/app/install-unlock.txt. Create it with the phrase below (in storage/app, not public_html), then try again.');
    }

    /** @param  Closure(): bool  $verify */
    private function unlock(Request $request, string $method, Closure $verify, string $refusal): Response
    {
        if (! InstallerController::secureEnough($request)) {
            return $this->screen->screen($request, 'Unlocking needs HTTPS. Turn it on as described below, then open this page with https://.', status: 403);
        }

        if (! self::sameOrigin($request)) {
            return $this->screen->screen($request, 'This request did not come from this installer page. Open the page again and use its buttons.', status: 403);
        }

        try {
            if (! $this->attempts->attempt((string) $request->ip())) {
                Log::channel('install')->warning('Unlock attempts over the limit', ['method' => $method]);

                return $this->screen->screen($request, 'Too many unlock attempts. Wait '.$this->attempts->decayMinutes().' minutes, then try again.', status: 429);
            }
        } catch (RuntimeException $exception) {
            return $this->screen->screen($request, $exception->getMessage().' Make the storage folder writable (755), then try again.', status: 503);
        }

        if (! $verify()) {
            Log::channel('install')->warning('Unlock refused', ['method' => $method]);

            return $this->screen->screen($request, $refusal, status: 422);
        }

        $this->attempts->clear((string) $request->ip());

        $environment = $this->app->make(EnvironmentFile::class);
        $this->steps->ensureApplicationKey($environment);
        // .env.example ships with debug pages on; nobody should see one now.
        $environment->set(['APP_DEBUG' => false]);
        $this->app->make('config')->set('app.debug', false);

        Log::channel('install')->info('Installer unlocked', ['method' => $method]);

        return $this->startSession($request);
    }

    /**
     * Starts the encrypted file session with the key that now exists and
     * hands it the grant: the session middleware runs here, inside the
     * request, because it could not run before the key existed.
     */
    private function startSession(Request $request): Response
    {
        $this->session->configure($request);

        return (new Pipeline($this->app))
            ->send($request)
            ->through([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class])
            ->then(function (Request $request): Response {
                $request->session()->migrate(true);
                $this->grant->issue($request->session());

                return new RedirectResponse($request->getBaseUrl().'/install/resume', 303, ['Cache-Control' => 'no-store']);
            });
    }

    /**
     * The Origin header must name this site, or (browsers that leave it
     * out) Sec-Fetch-Site says same-origin and the Referer is this site.
     */
    public static function sameOrigin(Request $request): bool
    {
        $expected = strtolower($request->getSchemeAndHttpHost());
        $origin = $request->headers->get('Origin');

        if (is_string($origin) && $origin !== '') {
            return strtolower(rtrim($origin, '/')) === $expected;
        }

        $referer = $request->headers->get('Referer');

        if ($request->headers->get('Sec-Fetch-Site') !== 'same-origin' || ! is_string($referer)) {
            return false;
        }

        $parts = parse_url($referer);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $referred = strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));

        return $referred === $expected;
    }
}
