<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

/*
 * Security review 2026-11, finding SR-01 (docs/security/review-2026-11.md):
 * links in emails are built from the request's host, so a request with a
 * forged Host header must never get as far as sending one. Laravel only
 * checks hosts outside local and testing, so these requests run as
 * production.
 */

beforeEach(function () {
    Notification::fake();
    app()['env'] = 'production';
    $this->withoutMiddleware(PreventRequestForgery::class);
    config(['app.url' => 'https://goproperty.test']);
});

afterEach(function () {
    app()['env'] = 'testing';
    // Symfony keeps the patterns in a static: later tests use any host.
    Request::setTrustedHosts([]);
});

it('refuses a password reset asked with another host, and sends no link', function () {
    $user = User::factory()->create(['email' => 'ada@adeyemi.ng']);

    $this->post('https://evil.example/forgot-password', ['identifier' => $user->email])->assertBadRequest();

    Notification::assertNothingSent();
});

it('sends the reset link when asked on the site\'s own host, and the link points there', function () {
    $user = User::factory()->create(['email' => 'ada@adeyemi.ng']);

    $this->post('https://goproperty.test/forgot-password', ['identifier' => $user->email])->assertRedirect();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $link = $notification->toMail($user)->actionUrl;

        return str_starts_with($link, 'https://goproperty.test/reset-password/');
    });
});

it('serves the site\'s subdomains and the console\'s own domain, nothing else', function (string $host, bool $served) {
    config(['app.admin_domain' => 'ops.example.ng']);

    $response = $this->get("https://{$host}/up");

    $served ? $response->assertOk() : $response->assertBadRequest();
})->with([
    'the site' => ['goproperty.test', true],
    'a subdomain of the site' => ['www.goproperty.test', true],
    'the console domain' => ['ops.example.ng', true],
    'another host' => ['evil.example', false],
    'a look-alike ending' => ['goproperty.test.evil.example', false],
    'a look-alike console' => ['xops.example.ng', false],
]);

it('serves the bare domain when the site\'s address is on www., but not its look-alikes', function (string $host, bool $served) {
    config(['app.url' => 'https://www.goproperty.test']);

    $response = $this->get("https://{$host}/up");

    $served ? $response->assertOk() : $response->assertBadRequest();
})->with([
    'the www address' => ['www.goproperty.test', true],
    'the bare domain' => ['goproperty.test', true],
    'another subdomain of the bare domain' => ['mail.goproperty.test', false],
    'a look-alike' => ['xgoproperty.test', false],
]);

it('checks no host before the install is finalized, when the installer runs at whatever address the owner opened', function () {
    File::delete((string) config('installer.paths.lock'));

    $this->get('https://203.0.113.10/up')->assertOk();
});

it('forgets the hosts of an earlier checked request in the same process when this one is not checked', function () {
    $this->get('https://goproperty.test/up')->assertOk();
    app()['env'] = 'testing';

    $this->get('https://elsewhere.example/up')->assertOk();
});
