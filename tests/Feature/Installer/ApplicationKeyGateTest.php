<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * P00-05 acceptance (first half): a fresh copy with no APP_KEY redirects to
 * /install without touching the database or writing error logs
 * (INSTALLER.md § 3). tests/Drill/FreshCopyDrillTest.php repeats this with a
 * real server process and an unreachable database.
 */

beforeEach(function () {
    $this->markNotInstalled();
    config(['app.key' => '']);
    $this->app->forgetInstance('encrypter');

    $this->queries = 0;
    DB::listen(function () {
        $this->queries++;
    });

    Log::spy();
});

afterEach(function () {
    expect($this->queries)->toBe(0, 'The request touched the database.');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('log');
});

it('redirects every GET to the installer', function (string $path) {
    $this->get($path)
        ->assertRedirect('/install')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertCookieMissing(config('session.cookie'));
})->with(['/', '/login', '/register', '/dashboard', '/up', '/o/acme', '/no-such-page']);

it('redirects HEAD requests too', function () {
    $this->call('HEAD', '/login')->assertRedirect('/install');
});

it('answers writes with a plain 503', function (string $method) {
    $response = $this->call($method, '/login', ['email' => 'a@example.com', 'password' => 'x']);

    $response->assertStatus(503)
        ->assertHeader('Retry-After', '300')
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertCookieMissing(config('session.cookie'));

    expect($response->getContent())->toBe("This site is being set up. Please try again later.\n");
})->with(['POST', 'PUT', 'DELETE']);

it('serves the installer’s first screen', function () {
    $this->get('/install')
        ->assertOk()
        ->assertSee('Install GoProperty')
        ->assertSee('Version dev')
        ->assertSee('/install.css', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertCookieMissing(config('session.cookie'));
});

it('treats a key that cannot encrypt as missing', function (string $key) {
    config(['app.key' => $key]);

    $this->get('/')->assertRedirect('/install');
})->with([
    'too short' => ['base64:'.base64_encode('short')],
    'not base64' => ['base64:%%%'],
    'raw text' => ['not-a-key'],
]);

it('lets requests through once the key exists', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    // Past the key check, the site still shows "being set up".
    $this->get('/')->assertStatus(503)->assertSee('is being set up');
});
