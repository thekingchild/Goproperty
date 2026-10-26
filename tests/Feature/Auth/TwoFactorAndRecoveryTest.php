<?php

declare(strict_types=1);

use App\Auth\Devices\KnownDevices;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\NewDeviceSignIn;
use App\Settings\SettingsStore;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/*
 * P00-10b: the 2FA challenge (login-2fa.html), "Don't ask again on this
 * device", new-device emails, and the forgot/reset/verify/confirm pages
 * (UX_DESIGN.md § 3.3).
 */

beforeEach(function () {
    Notification::fake();
    $this->secret = app(Google2FA::class)->generateSecretKey();
    $this->user = User::factory()->create([
        'email' => 'tola@adeyemirealty.ng',
        'phone' => '+2348031234567',
        'two_factor_secret' => encrypt($this->secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345', 'fghij-67890'])),
        'two_factor_confirmed_at' => now(),
    ]);
});

function code(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('shows the challenge from the design, with the account masked', function () {
    $this->post('/login', ['identifier' => '0803 123 4567', 'password' => 'password'])->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))->assertOk()
        ->assertSee('<h1>Two-step verification</h1>', false)
        ->assertSee('for <b>t•••@adeyemirealty.ng</b>', false)
        ->assertSee('One more step to <span>keep your money safe.</span>', false)
        ->assertSee('Codes come from your authenticator app — no SMS')
        ->assertSee("Don't ask again on this device for 30 days", false)
        ->assertSee('autocomplete="one-time-code"', false)
        ->assertSee('name="recovery_code"', false)
        ->assertSee('Use a recovery code')
        ->assertDontSee('tola@adeyemirealty.ng');
});

it('signs in with the app code and explains a wrong one', function () {
    $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->withSession(['login.id' => $this->user->id, 'errors' => ['default' => ['format' => ':message', 'messages' => ['code' => ['invalid']]]]])
        ->get(route('two-factor.login'))
        ->assertSee("That code didn't work. Codes change every 30 seconds");

    $this->post(route('two-factor.login.store'), ['code' => code($this->secret)])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->user);
});

it('signs in with a recovery code, once', function () {
    $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'abcde-12345'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->user);

    expect(json_decode(decrypt($this->user->fresh()->two_factor_recovery_codes), true))->not->toContain('abcde-12345');
});

it('skips the challenge on a trusted browser for the configured days only', function () {
    app(SettingsStore::class)->set('auth.trust_device_days', 10);

    $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password']);
    $response = $this->post(route('two-factor.login.store'), ['code' => code($this->secret), 'trust_device' => '1'])->assertRedirect('/dashboard');

    $token = $response->getCookie(KnownDevices::COOKIE)?->getValue();
    expect($token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and(UserDevice::query()->sole()->two_factor_trusted_until->isSameDay(now()->addDays(10)))->toBeTrue();

    $this->post(route('logout'));
    $this->assertGuest();

    // Same browser: straight in.
    $this->withCookie(KnownDevices::COOKIE, $token)
        ->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->user);
    $this->post(route('logout'));
    $this->assertGuest();

    // Another browser: challenged.
    $this->withCookie(KnownDevices::COOKIE, str_repeat('x', 64))
        ->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    // After ten days: challenged again.
    $this->travel(11)->days();
    $this->withCookie(KnownDevices::COOKIE, $token)
        ->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
});

it('does not trust the browser unless asked', function () {
    $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => code($this->secret)]);

    expect(UserDevice::query()->sole()->two_factor_trusted_until)->toBeNull();
});

it('emails the account when a new browser signs in', function () {
    $plain = User::factory()->create(['email' => 'ada@example.com']);

    $first = $this->post('/login', ['identifier' => 'ada@example.com', 'password' => 'password']);
    Notification::assertNothingSent();
    $token = $first->getCookie(KnownDevices::COOKIE)?->getValue();
    $this->post(route('logout'));
    $this->assertGuest();

    $this->withCookie(KnownDevices::COOKIE, $token)->post('/login', ['identifier' => 'ada@example.com', 'password' => 'password']);
    Notification::assertNothingSent();
    $this->post(route('logout'));
    $this->assertGuest();

    // A different browser (the test client keeps cookies, so replace it).
    $this->withCookie(KnownDevices::COOKIE, str_repeat('n', 64))
        ->withServerVariables(['HTTP_USER_AGENT' => 'Firefox on Windows', 'REMOTE_ADDR' => '198.51.100.20'])
        ->post('/login', ['identifier' => 'ada@example.com', 'password' => 'password']);

    Notification::assertSentTo($plain, NewDeviceSignIn::class, function (NewDeviceSignIn $notification) use ($plain) {
        $mail = $notification->toMail($plain);

        return $mail->subject === 'New sign-in to your GoProperty account'
            && in_array('Browser: Firefox on Windows', $mail->introLines, true)
            && in_array('IP address: 198.51.100.20', $mail->introLines, true);
    });
    expect($plain->devices()->count())->toBe(2);
});

it('answers every reset request the same way and emails only real accounts', function (string $typed) {
    $this->post(route('password.email'), ['identifier' => $typed])
        ->assertRedirect()
        ->assertSessionHas('status', PasswordResetLinkController::REPLY)
        ->assertSessionHasNoErrors();
})->with(['tola@adeyemirealty.ng', '0803 123 4567', 'nobody@example.com', '0809 000 0000', 'nonsense']);

it('sends the reset link to the account email, found by email or phone', function () {
    User::factory()->create(['email' => null, 'phone' => '+2348090000000']);

    $this->post(route('password.email'), ['identifier' => '0803 123 4567']);
    Notification::assertSentTo($this->user, ResetPassword::class);

    $this->post(route('password.email'), ['identifier' => '0809 000 0000']);
    Notification::assertCount(1);
});

it('renders the forgot, reset, verify and confirm pages in the sign-in layout', function () {
    $this->get(route('password.request'))->assertOk()->assertSee('<h1>Reset your password</h1>', false)->assertSee('Email or phone number');
    $this->get(route('password.reset', ['token' => 'abc', 'email' => 'tola@adeyemirealty.ng']))->assertOk()
        ->assertSee('<h1>Choose a new password</h1>', false)
        ->assertSee('<input type="hidden" name="email" value="tola@adeyemirealty.ng">', false)
        ->assertSee('minlength="10"', false);

    foreach ([route('password.request'), route('password.reset', ['token' => 'abc'])] as $url) {
        expect((string) $this->get($url)->headers->get('Content-Security-Policy'))->toContain("script-src 'self' https://challenges.cloudflare.com");
    }

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get(route('verification.notice'))->assertOk()->assertSee('<h1>Check your email</h1>', false);
    $this->actingAs($unverified)->get(route('password.confirm'))->assertOk()->assertSee('<h1>Confirm your password</h1>', false);
});

it('requires new passwords of at least 10 characters', function () {
    $token = app('auth.password.broker')->createToken($this->user);

    $this->post(route('password.update'), ['token' => $token, 'email' => $this->user->email, 'password' => 'short-pw1', 'password_confirmation' => 'short-pw1'])
        ->assertSessionHasErrors('password');

    $this->post(route('password.update'), ['token' => $token, 'email' => $this->user->email, 'password' => 'long-enough-pw', 'password_confirmation' => 'long-enough-pw'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});

it('keeps a 2FA sign-in remembered for the configured days', function () {
    $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password', 'remember' => '1']);

    $cookie = collect($this->post(route('two-factor.login.store'), ['code' => code($this->secret)])->headers->getCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

    expect($cookie?->getExpiresTime())->toBeBetween(now()->addDays(30)->subMinute()->getTimestamp(), now()->addDays(30)->addMinute()->getTimestamp());
});
