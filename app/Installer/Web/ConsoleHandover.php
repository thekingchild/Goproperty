<?php

declare(strict_types=1);

namespace App\Installer\Web;

use App\Http\Middleware\StaffIdleTimeout;
use App\Installer\EnvironmentFile;
use App\Models\PlatformStaff;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Finish, step 7 (INSTALLER.md § 5): the owner leaves the wizard signed in
 * to the console, without signing in again. The wizard has just enrolled
 * their password and authenticator app, so this is the same proof the
 * console's own sign-in asks for.
 *
 * The console session is created the way the finished site reads it: in
 * the `sessions` table (the finalizer has just switched SESSION_DRIVER to
 * database), under the staff cookie with the console's flags (see
 * App\Http\Middleware\UseConsoleSession). With the console on its own
 * domain (ADMIN_DOMAIN) a cookie set here would not reach it, so the owner
 * signs in there instead.
 */
final class ConsoleHandover
{
    public function __construct(
        private readonly Container $app,
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly AuthFactory $auth,
        private readonly Dispatcher $events,
    ) {}

    public function signIn(PlatformStaff $staff, Request $request, EnvironmentFile $environment): ?Cookie
    {
        $domain = $this->config->get('app.admin_domain');

        if (is_string($domain) && trim($domain) !== '') {
            return null;
        }

        $name = $this->string('console.session.cookie', 'gp_staff_session');
        $minutes = (int) $this->string('console.session.lifetime', '30');
        $handler = new DatabaseSessionHandler($this->db->connection(), $this->string('session.table', 'sessions'), $minutes, $this->app);
        $serialization = $this->string('session.serialization', 'php');

        $store = filter_var($environment->get('SESSION_ENCRYPT') ?? 'false', FILTER_VALIDATE_BOOL)
            ? new EncryptedStore($name, $handler, $this->app->make(Encrypter::class), null, $serialization)
            : new Store($name, $handler, null, $serialization);

        $store->start();
        $store->put($this->auth->guard('staff')->getName(), $staff->getAuthIdentifier());
        $store->put(StaffIdleTimeout::SESSION_KEY, now()->getTimestamp());
        $store->save();

        // The authentication log records the sign-in like any other.
        $this->events->dispatch(new Login('staff', $staff, false));

        return new Cookie(
            $name,
            $store->getId(),
            now()->addMinutes($minutes),
            '/',
            null,
            // As UseConsoleSession: Secure except on a local copy over http.
            $request->isSecure() || ! $this->app->make('app')->isLocal(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) || is_int($value) ? (string) $value : $default;
    }
}
