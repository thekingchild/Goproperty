<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Tests\Support\LivewireHttp;

/*
 * Security review 2026-11 (docs/security/review-2026-11.md): customer
 * sessions after a password change (SR-07), the Secure cookie flag (SR-05),
 * "Confirm your password" on Settings → Security's Livewire calls (SR-08)
 * and guessing the current password (SR-06).
 */

/** Signs in through the form, as a browser does; the guard then reads the session on each request. */
function signInThroughForm(User $user, string $password = 'password'): void
{
    test()->post(route('login.store'), ['identifier' => $user->email, 'password' => $password])->assertRedirect();
    app('auth')->forgetGuards();
}

it('signs a session out at its next request once the password was changed elsewhere (SR-07)', function () {
    $user = User::factory()->create();
    signInThroughForm($user);
    $this->get('/settings/profile')->assertOk();

    // Changed from another browser, or reset through "Forgot password".
    User::query()->whereKey($user->id)->update(['password' => Hash::make('a-new-long-password')]);
    app('auth')->forgetGuards();

    $this->get('/settings/profile')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('starts afresh when the session signs another account in, as accepting an invitation does (SR-07)', function () {
    signInThroughForm($first = User::factory()->create());
    $this->get('/settings/profile')->assertOk();

    $second = User::factory()->create();
    auth()->login($second);
    app('auth')->forgetGuards();

    $this->get('/settings/profile')->assertOk();
    $this->assertAuthenticatedAs($second);
    expect($first->id)->not->toBe($second->id);
});

it('keeps the session that changed the password signed in (SR-07)', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = User::factory()->create();
    signInThroughForm($user);

    $page = $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
    LivewireHttp::update($this, LivewireHttp::snapshot($page), [
        'current_password' => 'password', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password',
    ], [['updatePassword']])->assertOk();
    app('auth')->forgetGuards();

    expect(Hash::check('a-new-long-password', (string) $user->fresh()->password))->toBeTrue();
    $this->get('/settings/profile')->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('asks for the password again when Settings → Security is used past the confirmation\'s time, also from an open page (SR-08)', function () {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = User::factory()->withTwoFactor()->create();
    signInThroughForm($user);
    // Two-factor users finish signing in with their code; acting as them is enough here.
    $this->actingAs($user);

    $page = $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
    $snapshot = LivewireHttp::snapshot($page);

    // Four hours later the page is still open; the confirmation lasts three.
    $this->travel(4)->hours();
    LivewireHttp::update($this, $snapshot, [], [['disable']])->assertRedirect(route('password.confirm'));

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(session('url.intended'))->toBe(route('security.edit'));
});

it('limits guesses of the current password: confirm, change and delete share 5 a minute (SR-06)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 5) as $try) {
        $this->post(route('password.confirm.store'), ['password' => "wrong-{$try}"])->assertSessionHasErrors('password');
    }

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertTooManyRequests();

    $this->travel(61)->seconds();
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertSessionHasNoErrors();
});

it('limits guesses at the change-password and delete-account forms (SR-06)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 5) as $try) {
        Livewire\Livewire::test('pages::settings.security')
            ->set('current_password', "wrong-{$try}")->set('password', 'a-new-long-password')->set('password_confirmation', 'a-new-long-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);
    }

    Livewire\Livewire::test('pages::settings.security')
        ->set('current_password', 'password')->set('password', 'a-new-long-password')->set('password_confirmation', 'a-new-long-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);
    Livewire\Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);

    expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue()
        ->and($user->fresh())->not->toBeNull();
});

it('marks the session cookie Secure whenever the site\'s address is https, unless set otherwise (SR-05)', function (string $url, ?string $setting, bool $secure) {
    $saved = [$_SERVER['APP_URL'] ?? null, $_ENV['APP_URL'] ?? null, $_SERVER['SESSION_SECURE_COOKIE'] ?? null, $_ENV['SESSION_SECURE_COOKIE'] ?? null];
    $_SERVER['APP_URL'] = $_ENV['APP_URL'] = $url;
    unset($_SERVER['SESSION_SECURE_COOKIE'], $_ENV['SESSION_SECURE_COOKIE']);

    if ($setting !== null) {
        $_SERVER['SESSION_SECURE_COOKIE'] = $_ENV['SESSION_SECURE_COOKIE'] = $setting;
    }

    try {
        $config = require config_path('session.php');
        expect($config['secure'])->toBe($secure)
            ->and($config['http_only'])->toBeTrue()
            ->and($config['same_site'])->toBe('lax');
    } finally {
        foreach (['APP_URL' => [0, 1], 'SESSION_SECURE_COOKIE' => [2, 3]] as $name => [$server, $env]) {
            $saved[$server] === null ? Arr::forget($_SERVER, $name) : $_SERVER[$name] = $saved[$server];
            $saved[$env] === null ? Arr::forget($_ENV, $name) : $_ENV[$name] = $saved[$env];
        }
    }
})->with([
    'an https site' => ['https://goproperty.ng', null, true],
    'a local copy on http' => ['http://localhost', null, false],
    'turned off by setting' => ['https://goproperty.ng', 'false', false],
    'turned on by setting' => ['http://localhost', 'true', true],
]);
