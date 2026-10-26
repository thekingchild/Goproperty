<?php

declare(strict_types=1);

use App\Auth\SignIn\Turnstile;
use App\Http\Controllers\Onboarding\RegisterController;
use App\Models\User;
use App\Notifications\SignupForExistingAccount;
use App\Settings\SettingsStore;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
 * Signup, account step (ONBOARDING_COMPLIANCE.md § 2 step 1) to the same
 * standard as sign-in (UX_DESIGN.md § 3.1): Nigerian mobile number stored
 * as +234…, breached-password check, Turnstile, rate limits, no account
 * enumeration, and the email verified by link. No SMS anywhere.
 */

beforeEach(function () {
    Notification::fake();
});

/** @param  array<string, string>  $overrides */
function signupForm(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Ada Adeyemi',
        'email' => 'ada@adeyemi.ng',
        'phone' => '0803 123 4567',
        'password' => 'a-long-password-1',
    ];
}

function postSignup(array $form, string $ip = '203.0.113.9'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post('/register', $form);
}

function turnstileForSignup(string $mode): void
{
    $settings = app(SettingsStore::class);
    $settings->set('auth.turnstile_site_key', '0x4AAAAAAAsite');
    $settings->set('auth.turnstile_secret_key', '0x4AAAAAAAsecret');
    $settings->set('auth.turnstile_mode', $mode);
}

it('renders the account step on the sign-in layout', function () {
    $this->get('/register')->assertOk()
        ->assertSee('Start your free trial')
        ->assertSee('Mobile number')
        ->assertSee('We never send SMS codes')
        ->assertSee('Step 1 of 7')
        ->assertSee('aria-current="step"', false)
        ->assertHeader('Content-Security-Policy')
        ->assertDontSee('cf-turnstile', false);
});

it('stores the mobile number as +234…', function (string $typed) {
    postSignup(signupForm(['phone' => $typed]))->assertRedirect(route('register.sent'));

    expect(User::query()->where('email', 'ada@adeyemi.ng')->value('phone'))->toBe('+2348031234567');
})->with(['08031234567', '0803 123 4567', '+234 803 123 4567', '2348031234567', '(0803) 123-4567']);

it('refuses what is not a Nigerian mobile number, and other bad input', function (array $form, string $field) {
    postSignup(signupForm($form))->assertSessionHasErrors($field);

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'landline' => [['phone' => '01 234 5678'], 'phone'],
    'too short' => [['phone' => '0803 123'], 'phone'],
    'foreign' => [['phone' => '+44 7700 900123'], 'phone'],
    'letters' => [['phone' => 'call me'], 'phone'],
    'no phone' => [['phone' => ''], 'phone'],
    'bad email' => [['email' => 'ada@'], 'email'],
    'no name' => [['name' => ''], 'name'],
    'short password' => [['password' => 'short-pw'], 'password'],
]);

it('refuses a password from a breach list in production', function () {
    $password = 'a-long-password-1';
    $hash = strtoupper(sha1($password));
    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":42\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1"),
    ]);
    $this->app['env'] = 'production';

    // Production also turns on CSRF checks that tests otherwise skip.
    $this->withoutMiddleware(PreventRequestForgery::class);

    postSignup(signupForm(['password' => $password]))->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)));
});

it('creates an unverified account, signs nobody in and sends the link', function () {
    postSignup(signupForm(['email' => 'Ada@Adeyemi.NG']))->assertRedirect(route('register.sent'));

    $this->assertGuest();
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();
    expect($user->email_verified_at)->toBeNull()
        ->and(Hash::check('a-long-password-1', $user->password))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->get(route('register.sent'))->assertOk()->assertSee('Check your email')->assertSee('ada@adeyemi.ng');
});

it('answers an address that already has an account exactly like a new one', function () {
    $existing = User::factory()->create(['email' => 'tola@adeyemi.ng', 'phone' => '+2348099999999', 'password' => 'the-old-password']);

    $new = postSignup(signupForm(['email' => 'new@adeyemi.ng', 'phone' => '0803 000 0001']));
    $newPage = $this->get(route('register.sent'))->getContent();
    $taken = postSignup(signupForm(['email' => 'TOLA@adeyemi.ng', 'phone' => '0803 000 0002']));
    $takenPage = $this->get(route('register.sent'))->getContent();

    expect($taken->status())->toBe($new->status())
        ->and($taken->headers->get('Location'))->toBe($new->headers->get('Location'))
        ->and(str_replace('tola@adeyemi.ng', 'new@adeyemi.ng', mainOf((string) $takenPage)))->toBe(mainOf((string) $newPage))
        ->and(User::query()->where('email', 'tola@adeyemi.ng')->count())->toBe(1)
        ->and(Hash::check('the-old-password', $existing->fresh()->password))->toBeTrue()
        ->and($existing->fresh()->name)->toBe($existing->name);

    $this->assertGuest();
    Notification::assertSentTo($existing, SignupForExistingAccount::class);
    Notification::assertNotSentTo($existing, VerifyEmail::class);
});

it('keeps the phone number off a new account when another account has it', function () {
    User::factory()->create(['phone' => '+2348031234567']);

    postSignup(signupForm())->assertRedirect(route('register.sent'))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'ada@adeyemi.ng')->value('phone'))->toBeNull();
});

it('requires the bot check on every signup in "always" mode', function () {
    turnstileForSignup('always');
    $this->get('/register')->assertSee('class="turnstile cf-turnstile"', false);

    postSignup(signupForm())->assertSessionHasErrors(['turnstile' => RegisterController::CHECK_FAILED]);
    expect(User::query()->count())->toBe(0);

    Http::fake([Turnstile::VERIFY_URL => fn ($request) => Http::response(['success' => $request['response'] === 'good-token'])]);
    postSignup(signupForm() + ['cf-turnstile-response' => 'bad-token'])->assertSessionHasErrors('turnstile');
    expect(User::query()->count())->toBe(0);

    postSignup(signupForm() + ['cf-turnstile-response' => 'good-token'])->assertRedirect(route('register.sent'));
    expect(User::query()->count())->toBe(1);
});

it('asks for the bot check after three signups from one IP in "after failures" mode', function () {
    turnstileForSignup('after_failures');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/register')->assertDontSee('cf-turnstile', false);

    foreach (range(1, 3) as $n) {
        postSignup(signupForm(['email' => "agent{$n}@adeyemi.ng", 'phone' => "0803000000{$n}"]))->assertRedirect(route('register.sent'));
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/register')->assertSee('class="turnstile cf-turnstile"', false);
    postSignup(signupForm(['email' => 'agent4@adeyemi.ng']))->assertSessionHasErrors('turnstile');
    postSignup(signupForm(['email' => 'agent5@adeyemi.ng']), '198.51.100.20')->assertRedirect(route('register.sent'));

    expect(User::query()->pluck('email')->sort()->values()->all())
        ->toBe(['agent1@adeyemi.ng', 'agent2@adeyemi.ng', 'agent3@adeyemi.ng', 'agent5@adeyemi.ng']);
});

it('limits signups per IP address', function () {
    foreach (range(1, 5) as $n) {
        postSignup(signupForm(['email' => "agent{$n}@adeyemi.ng", 'phone' => '080300000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]))->assertRedirect();
    }

    postSignup(signupForm(['email' => 'agent6@adeyemi.ng']))->assertTooManyRequests();
    postSignup(signupForm(['email' => 'agent7@adeyemi.ng']), '198.51.100.20')->assertRedirect();
});

it('limits signups per email address, whoever sends them', function () {
    foreach (range(1, 6) as $n) {
        postSignup(signupForm(), "198.51.100.{$n}")->assertRedirect();
    }

    postSignup(signupForm(), '198.51.100.99')->assertTooManyRequests();
});

it('signs the new account in from the link opened in the same browser', function () {
    postSignup(signupForm());
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();

    $this->get(verificationLink($user))->assertRedirect('/dashboard?verified=1');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get('/dashboard')->assertRedirect('/signup/organization');
});

it('asks another browser to sign in before confirming', function () {
    postSignup(signupForm());
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();
    $link = verificationLink($user);

    $this->flushSession();
    $this->get($link)->assertRedirect(route('login'));
    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    // Signing in returns to the link, which then confirms the address.
    $this->post('/login', ['identifier' => 'ada@adeyemi.ng', 'password' => 'a-long-password-1'])->assertRedirect($link);
    $this->get($link)->assertRedirect('/dashboard?verified=1');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses a link with a wrong hash, an expired link or someone else\'s', function () {
    postSignup(signupForm());
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();

    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@adeyemi.ng')]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1('ada@adeyemi.ng')]))->assertForbidden();
    $this->get(str_replace('signature=', 'signature=x', verificationLink($user)))->assertForbidden();

    $this->actingAs(User::factory()->create())->get(verificationLink($user))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('never signs an account with two-factor in from a link alone', function () {
    postSignup(signupForm());
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->get(verificationLink($user))->assertRedirect(route('login'));
    $this->assertGuest();
});

/** The page's main column (asset preloads differ between renders in one test process). */
function mainOf(string $html): string
{
    return preg_match('#<main\b.*</main>#s', $html, $match) === 1 ? $match[0] : '';
}

function verificationLink(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1((string) $user->email)]);
}
