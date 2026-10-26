<?php

declare(strict_types=1);

use App\Auth\SignIn\GuardSignIn;
use App\Auth\SignIn\Turnstile;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;

/*
 * P00-10a: customer sign-in (UX_DESIGN.md § 3.1, PLATFORM_CONSOLE.md § 9.6):
 * email or phone number, one generic error, lockout, Cloudflare Turnstile
 * (fails closed), rate limits.
 */

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'tola@adeyemirealty.ng', 'phone' => '+2348031234567']);
});

function signIn(string $identifier, string $password = 'password', array $extra = [], string $ip = '203.0.113.7')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['identifier' => $identifier, 'password' => $password] + $extra);
}

function withTurnstile(string $mode = 'after_failures'): void
{
    $settings = app(SettingsStore::class);
    $settings->set('auth.turnstile_site_key', '0x4AAAAAAAsite');
    $settings->set('auth.turnstile_secret_key', '0x4AAAAAAAsecret');
    $settings->set('auth.turnstile_mode', $mode);
}

it('renders the sign-in page from the design, with a strict security policy', function () {
    $response = $this->get('/login')->assertOk()
        ->assertSee('<h1>Welcome back</h1>', false)
        ->assertSee('Sign in to your GoProperty account.')
        ->assertSee('Email or phone number')
        ->assertSee('autocomplete="username webauthn"', false)
        ->assertSee('Keep me signed in on this device')
        ->assertSee(route('password.request'), false)
        ->assertSee('data-passkey-block', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertSee('Manage properties, rent and tenants <span>in one place.</span>', false)
        ->assertDontSee('cf-turnstile', false);

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self' https://challenges.cloudflare.com")
        ->not->toContain("script-src 'self' https://challenges.cloudflare.com 'unsafe-inline'")
        ->toContain("frame-ancestors 'none'");
});

it('never prints custom code on the sign-in page', function () {
    app(SettingsStore::class)->set('custom_code.head', '<script>window.tracker=1</script>');

    $this->get('/login')->assertDontSee('window.tracker', false);
});

it('signs in with an email or a phone number in any common format', function (string $identifier) {
    signIn($identifier)->assertSessionHasNoErrors()->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($this->user);
})->with(['tola@adeyemirealty.ng', 'TOLA@AdeyemiRealty.ng ', '0803 123 4567', '+234 803 123 4567', '2348031234567']);

it('gives the same answer for an unknown account as for a wrong password', function () {
    $answer = function (string $identifier, string $ip): array {
        $response = signIn($identifier, 'wrong-password', ip: $ip);

        // JSON session serialisation stores the bag as an array.
        $errors = session('errors');
        $messages = $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : ($errors['default']['messages'] ?? null);

        return [$response->status(), $response->headers->get('Location'), $messages];
    };

    $answers = collect([
        $answer('tola@adeyemirealty.ng', '203.0.113.1'),
        $answer('nobody@example.com', '203.0.113.2'),
        $answer('0809 999 9999', '203.0.113.3'),
        $answer('not-an-identifier', '203.0.113.4'),
    ]);

    expect($answers->unique()->count())->toBe(1)
        ->and($answers->first()[2])->toBe(['identifier' => [GuardSignIn::FAILED]]);
    $this->assertGuest();
});

it('pauses sign-in after 3 failures when Turnstile is not set up', function () {
    foreach (range(1, 3) as $attempt) {
        signIn('0803 123 4567', 'wrong-password');
    }

    // Even the right password is refused while paused, from this IP.
    signIn('tola@adeyemirealty.ng')
        ->assertSessionHasErrors(['identifier' => 'For your security, sign-in is paused for 15 minutes.'])
        ->assertSessionHas(GuardSignIn::LOCKED);
    $this->assertGuest();

    // Another IP is not paused.
    signIn('tola@adeyemirealty.ng', ip: '198.51.100.9')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->user);
    auth()->logout();

    $this->travel(16)->minutes();
    signIn('tola@adeyemirealty.ng')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->user);
});

it('shows the locked state with a password-reset link and no way to submit', function () {
    // Sessions are JSON-serialised: errors are stored as arrays and rebuilt on load.
    $errors = ['default' => ['format' => ':message', 'messages' => ['identifier' => ['For your security, sign-in is paused for 15 minutes.']]]];

    $this->withSession([GuardSignIn::LOCKED => 900, 'errors' => $errors])
        ->get('/login')
        ->assertSee('<b>Too many attempts</b>For your security, sign-in is paused for 15 minutes.', false)
        ->assertSee('<a class="link" href="'.route('password.request').'">reset your password</a>', false)
        ->assertSee('data-submit disabled', false);
});

it('shows the bot check when it is due', function () {
    withTurnstile();

    $this->withSession([GuardSignIn::SHOW_CHECK => true])->get('/login')
        ->assertSee('class="turnstile cf-turnstile"', false)
        ->assertSee('data-sitekey="0x4AAAAAAAsite"', false)
        ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false);
});

it('asks for the bot check after 3 failures, then pauses after 5', function () {
    withTurnstile();
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true])]);

    signIn('tola@adeyemirealty.ng', 'wrong-1');
    signIn('tola@adeyemirealty.ng', 'wrong-2');
    signIn('tola@adeyemirealty.ng', 'wrong-3')
        ->assertSessionHasErrors(['identifier' => GuardSignIn::FAILED_CHECK])
        ->assertSessionHas(GuardSignIn::SHOW_CHECK);

    // Without the check's token the attempt is refused before the password is looked at.
    signIn('tola@adeyemirealty.ng')->assertSessionHasErrors(['identifier' => GuardSignIn::CHECK_FAILED]);
    $this->assertGuest();

    signIn('tola@adeyemirealty.ng', 'wrong-4', ['cf-turnstile-response' => 'token']);
    signIn('tola@adeyemirealty.ng', 'wrong-5', ['cf-turnstile-response' => 'token'])->assertSessionHas(GuardSignIn::LOCKED);
    signIn('tola@adeyemirealty.ng', 'password', ['cf-turnstile-response' => 'token'])->assertSessionHas(GuardSignIn::LOCKED);
    $this->assertGuest();

    Http::assertSent(fn ($request) => $request['secret'] === '0x4AAAAAAAsecret' && $request['response'] === 'token' && $request['remoteip'] === '203.0.113.7');
});

it('signs in once the bot check passes', function () {
    withTurnstile('always');
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true])]);

    signIn('tola@adeyemirealty.ng')->assertSessionHasErrors(['identifier' => GuardSignIn::CHECK_FAILED]);
    signIn('tola@adeyemirealty.ng', extra: ['cf-turnstile-response' => 'token'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($this->user);
});

it('fails the bot check closed', function (Closure $fake) {
    withTurnstile('always');
    $fake();

    signIn('tola@adeyemirealty.ng', extra: ['cf-turnstile-response' => 'token'])
        ->assertSessionHasErrors(['identifier' => GuardSignIn::CHECK_FAILED]);
    $this->assertGuest();
})->with([
    'rejected' => fn () => fn () => Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]),
    'server error' => fn () => fn () => Http::fake([Turnstile::VERIFY_URL => Http::response('oops', 500)]),
    'unreachable' => fn () => fn () => Http::fake([Turnstile::VERIFY_URL => fn () => throw new ConnectionException('timed out')]),
    'odd answer' => fn () => fn () => Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => 'yes'])]),
]);

it('keeps people signed in for the configured days only when asked', function () {
    app(SettingsStore::class)->set('auth.remember_days', 30);

    $cookie = collect(signIn('tola@adeyemirealty.ng', extra: ['remember' => '1'])->headers->getCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeBetween(now()->addDays(30)->subMinute()->getTimestamp(), now()->addDays(30)->addMinute()->getTimestamp());

    auth()->logout();
    $plain = collect(signIn('tola@adeyemirealty.ng', ip: '198.51.100.1')->headers->getCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
    expect($plain)->toBeNull();
});

it('rate limits sign-in requests from one IP', function () {
    foreach (range(1, 30) as $attempt) {
        signIn("person{$attempt}@example.com", 'wrong-password');
    }

    signIn('person31@example.com', 'wrong-password')->assertStatus(429);
});

it('turns passkey sign-in off on the page and the server', function () {
    $this->get(route('passkey.login-options'))->assertOk();

    app(SettingsStore::class)->set('auth.passkeys_enabled', false);

    $this->get('/login')->assertDontSee('data-passkey-block', false);
    $this->get(route('passkey.login-options'))->assertNotFound();
    $this->post(route('passkey.login'))->assertNotFound();
});
