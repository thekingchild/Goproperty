<?php

declare(strict_types=1);

use App\Installer\ApplicationKey;
use App\Installer\EnvironmentFile;
use App\Installer\Requirements\ServerEnvironment;
use App\Installer\SetupToken;
use App\Installer\Web\InstallerGrant;
use App\Installer\Web\UnlockChallenge;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\FakeServer;
use Tests\Support\InstallerBrowser;

/*
 * P01-09: unlocking the web installer (INSTALLER.md § 4) by setup token or
 * file check, and the session-bound grant: rotated on first use, renewed by
 * every completed step, 60 minutes from the last one.
 */

beforeEach(function () {
    $this->markNotInstalled();
    $this->dir = sys_get_temp_dir().'/goproperty-unlock-'.Str::random(10);
    File::ensureDirectoryExists($this->dir.'/sessions');
    $this->env = new EnvironmentFile($this->dir.'/.env', base_path('.env.example'));
    $this->app->instance(EnvironmentFile::class, $this->env);
    $this->app->instance(ServerEnvironment::class, FakeServer::make());
    config(['installer.session_files' => $this->dir.'/sessions']);
    $this->browser = new InstallerBrowser($this, $this->app);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function unlockPhrase(InstallerBrowser $browser): string
{
    $browser->get('/install')->assertOk();

    expect(preg_match('/id="unlock-phrase">([^<]+)</', $browser->page(), $match))->toBe(1);

    return html_entity_decode($match[1]);
}

function writeUnlockFile(string $contents): void
{
    File::ensureDirectoryExists(dirname(config('installer.paths.unlock_file')));
    file_put_contents(config('installer.paths.unlock_file'), $contents);
}

it('shows both ways to unlock, with a fresh phrase and no cookie', function () {
    $response = $this->browser->get('/install')->assertOk();

    $response->assertSee('Unlock with a file (no SSH)')
        ->assertSee('Unlock with a setup token (SSH)')
        ->assertSee('php artisan goproperty:setup-token')
        ->assertSee('install-unlock.txt')
        ->assertSee('aria-current="step"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->getCookies())->toBe([])
        ->and(unlockPhrase($this->browser))->toMatch('/^GP-UNLOCK [A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/');
});

it('unlocks with the file check, creates .env with a key and starts the installer session', function () {
    writeUnlockFile("\xEF\xBB\xBF".strtolower(unlockPhrase($this->browser))."\r\n");

    $response = $this->browser->post('/install/unlock/file');

    $response->assertStatus(303)->assertRedirect('/install/resume');

    expect(ApplicationKey::usable($this->env->get('APP_KEY'), 'AES-256-CBC'))->toBeTrue()
        ->and($this->env->get('APP_DEBUG'))->toBe('false')
        ->and(substr(sprintf('%o', fileperms($this->env->path())), -4))->toBe('0600')
        ->and(File::exists(config('installer.paths.unlock_file')))->toBeFalse()
        ->and(File::exists(config('installer.paths.unlock_challenge')))->toBeFalse()
        ->and(File::exists(config('installer.paths.grant')))->toBeTrue()
        ->and($this->browser->cookies)->toHaveKey('gp_install_session');

    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'gp_install_session');

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/install');

    $this->browser->follow($response)->assertOk()->assertSee('Server check')->assertSee('PHP extension: intl');
});

it('unlocks with the setup token and keeps an existing key', function () {
    $this->env->ensureExists();
    $key = 'base64:'.base64_encode(random_bytes(32));
    $this->env->set(['APP_KEY' => $key]);
    $token = app(SetupToken::class)->issue();

    $this->browser->get('/install');
    $this->browser->post('/install/unlock/token', ['token' => $token])->assertRedirect('/install/resume');

    expect($this->env->get('APP_KEY'))->toBe($key)
        ->and(config('app.key'))->toBe($key);
});

it('refuses a wrong token or phrase without a session', function (string $method) {
    app(SetupToken::class)->issue();
    unlockPhrase($this->browser);
    writeUnlockFile('GP-UNLOCK AAAA-BBBB-CCCC');

    $response = $method === 'token'
        ? $this->browser->post('/install/unlock/token', ['token' => Str::random(40)])
        : $this->browser->post('/install/unlock/file');

    $response->assertStatus(422)->assertSee('not', false);

    expect($response->headers->getCookies())->toBe([])
        ->and($this->env->exists())->toBeFalse()
        ->and(File::exists(config('installer.paths.grant')))->toBeFalse();
})->with(['token', 'file']);

it('says where the unlock file goes when it is missing', function () {
    unlockPhrase($this->browser);

    $this->browser->post('/install/unlock/file')->assertStatus(422)->assertSee('cannot find storage/app/install-unlock.txt');
});

it('refuses a phrase older than 30 minutes', function () {
    writeUnlockFile(unlockPhrase($this->browser));
    $this->travel(31)->minutes();

    $this->browser->post('/install/unlock/file')->assertStatus(422)->assertSee('last 30 minutes');
});

it('keeps earlier phrases valid when the page is reloaded', function () {
    $first = unlockPhrase($this->browser);
    unlockPhrase($this->browser);
    unlockPhrase($this->browser);
    writeUnlockFile($first);

    $this->browser->post('/install/unlock/file')->assertRedirect('/install/resume');
});

it('refuses an unlock file that is a symlink', function () {
    $target = $this->dir.'/elsewhere.txt';
    file_put_contents($target, unlockPhrase($this->browser));
    File::ensureDirectoryExists(dirname(config('installer.paths.unlock_file')));
    symlink($target, config('installer.paths.unlock_file'));

    $this->browser->post('/install/unlock/file')->assertStatus(422);
});

it('allows 5 attempts per IP address in 15 minutes', function () {
    $token = app(SetupToken::class)->issue();

    for ($i = 0; $i < 5; $i++) {
        $this->browser->post('/install/unlock/token', ['token' => Str::random(40)])->assertStatus(422);
    }

    // The 6th is refused even with the right token.
    $this->browser->post('/install/unlock/token', ['token' => $token])->assertStatus(429)->assertSee('Too many unlock attempts');

    // Another address still may.
    $this->browser->post('/install/unlock/token', ['token' => $token], ['REMOTE_ADDR' => '10.0.0.9'])->assertRedirect('/install/resume');

    $this->travel(16)->minutes();
    $this->browser->post('/install/unlock/token', ['token' => $token])->assertRedirect('/install/resume');
});

it('refuses unlock posts that do not come from the installer page', function (array $server, int $status) {
    $token = app(SetupToken::class)->issue();

    $this->browser->post('/install/unlock/token', ['token' => $token], $server)->assertStatus($status);
})->with([
    'another site' => [['HTTP_ORIGIN' => 'https://evil.example'], 403],
    'no origin, no fetch metadata' => [['HTTP_ORIGIN' => ''], 403],
    'same-origin fetch with a foreign referer' => [['HTTP_ORIGIN' => '', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'https://evil.example/install'], 403],
    'same-origin fetch with this referer' => [['HTTP_ORIGIN' => '', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'http://localhost/install'], 303],
    'cross-site fetch' => [['HTTP_ORIGIN' => '', 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'http://localhost/install'], 403],
]);

it('requires HTTPS except on a local copy', function () {
    $token = app(SetupToken::class)->issue();
    $browser = new InstallerBrowser($this, $this->app, 'http://goproperty.ng');

    $browser->get('http://goproperty.ng/install')->assertOk()->assertSee('Turn on HTTPS first')->assertSee('AutoSSL');
    $browser->post('http://goproperty.ng/install/unlock/token', ['token' => $token])->assertStatus(403)->assertSee('Unlocking needs HTTPS');

    expect($this->env->exists())->toBeFalse();

    $secure = new InstallerBrowser($this, $this->app, 'https://goproperty.ng');
    $response = $secure->post('https://goproperty.ng/install/unlock/token', ['token' => $token]);

    $response->assertRedirect('https://goproperty.ng/install/resume');

    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'gp_install_session');

    expect($cookie->isSecure())->toBeTrue();
});

it('sends steps without the grant back to the first screen', function () {
    $this->env->ensureExists();

    foreach (['/install/server', '/install/site', '/install/database', '/install/services', '/install/admin', '/install/finish', '/install/resume'] as $path) {
        $this->browser->get($path)->assertRedirect('/install?session=missing');
    }

    $this->browser->post('/install/finish')->assertRedirect('/install?session=missing');
    $this->browser->get('/install?session=missing')->assertSee('Unlock the installer in this browser to continue');
});

it('sends wizard steps to the first screen before APP_KEY exists', function () {
    config(['app.key' => '']);
    $this->app->forgetInstance('encrypter');

    $this->browser->get('/install/server')->assertRedirect('/install');
    expect($this->browser->cookies)->toBe([]);
});

it('rotates the grant on first use and binds it to the session', function () {
    $token = app(SetupToken::class)->issue();
    $this->browser->post('/install/unlock/token', ['token' => $token]);
    $unlockCookies = $this->browser->cookies;
    $grantAtUnlock = File::get(config('installer.paths.grant'));

    $this->browser->get('/install/server')->assertOk();

    expect(File::get(config('installer.paths.grant')))->not->toBe($grantAtUnlock)
        ->and(json_decode(File::get(config('installer.paths.grant')), true)['rotated'])->toBeTrue()
        ->and($this->browser->cookies['gp_install_session'])->not->toBe($unlockCookies['gp_install_session']);

    // The cookie from the unlock response is useless after the rotation.
    $replay = new InstallerBrowser($this, $this->app);
    $replay->cookies = $unlockCookies;
    $replay->get('/install/server')->assertRedirect('/install?session=missing');

    // Another browser has no grant at all.
    (new InstallerBrowser($this, $this->app))->get('/install/server')->assertRedirect('/install?session=missing');

    // The rotated session keeps working.
    $this->browser->get('/install/server')->assertOk();
});

it('ends an older session when someone unlocks again', function () {
    $token = app(SetupToken::class)->issue();
    $this->browser->post('/install/unlock/token', ['token' => $token]);
    $this->browser->get('/install/server')->assertOk();

    $other = new InstallerBrowser($this, $this->app);
    $other->post('/install/unlock/token', ['token' => $token]);
    $other->get('/install/server')->assertOk();

    $this->browser->get('/install/server')->assertRedirect('/install?session=missing');
});

it('expires 60 minutes after the last completed step, and each step renews it', function () {
    $token = app(SetupToken::class)->issue();
    $this->browser->post('/install/unlock/token', ['token' => $token]);
    $this->browser->get('/install/server')->assertOk();

    // Completing a step at minute 50 renews the grant...
    $this->travel(50)->minutes();
    $this->browser->post('/install/server')->assertRedirect('/install/site');

    // ...so at minute 100 it is still valid (50 minutes after that step),
    $this->travel(50)->minutes();
    $this->browser->get('/install/site')->assertOk();

    // but merely looking at pages does not renew it.
    $this->travel(11)->minutes();
    $this->browser->get('/install/site')->assertRedirect('/install?session=expired');
    $this->browser->get('/install?session=expired')->assertSee('Unlock again to carry on where you stopped');

    // Unlocking again in the browser is all it takes.
    $this->browser->post('/install/unlock/token', ['token' => $token])->assertRedirect('/install/resume');
    $this->browser->follow($this->browser->get('/install/resume'))->assertOk()->assertSee('Site &amp; database', false);

    expect(app(InstallerGrant::class)->minutes())->toBe(60);
});

it('opens the steps in order', function () {
    $token = app(SetupToken::class)->issue();
    $this->browser->post('/install/unlock/token', ['token' => $token]);

    $this->browser->get('/install/database')->assertRedirect('/install/server');
    $this->browser->get('/install/finish')->assertRedirect('/install/server');
    $this->browser->post('/install/admin', ['name' => 'Eve'])->assertRedirect('/install/server');
});

it('normalizes the unlock phrase the way File Manager editors save it', function () {
    expect(UnlockChallenge::normalize("\xEF\xBB\xBFgp-unlock  7q4m-k2xd-pl9a\r\n"))->toBe('GP-UNLOCK 7Q4M-K2XD-PL9A');
});
