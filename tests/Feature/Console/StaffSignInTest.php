<?php

declare(strict_types=1);

use App\Http\Middleware\StaffIdleTimeout;
use App\Models\PlatformStaff;
use App\Platform\Filament\Auth\StaffLogin;
use App\Platform\Filament\Pages\Settings\SignInSecuritySettings;
use App\Settings\SettingsStore;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P00-10c: staff sign-in (UX_DESIGN.md § 3.2): mandatory 2FA, one generic
 * error, rate limit, 30-minute idle timeout, optional IP allowlist; and the
 * Sign-in security settings page (PLATFORM_CONSOLE.md § 9.6).
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    $this->console = '/'.config('app.admin_path');
});

function staffOtp(PlatformStaff $staff): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret);
}

it('shows a plain staff sign-in with no sign-up link or marketing', function () {
    $this->get($this->console.'/login')->assertOk()
        ->assertSee('Staff console')
        ->assertSee('Two-factor authentication is required.')
        ->assertSee('.fi-simple-layout { background:', false)
        ->assertDontSee('Start free trial')
        ->assertDontSee(route('register'), false);
});

it('cannot reach any console page without setting up 2FA', function () {
    $staff = staffMember(withTwoFactor: false);

    Livewire::test(StaffLogin::class)
        ->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1'])
        ->call('authenticate');

    $setUp = route('filament.console.auth.multi-factor-authentication.set-up-required');

    foreach (['', '/settings/brand', '/settings/sign-in-security', '/theme-preview/app', '/theme-preview/landing', '/session/ping'] as $path) {
        $this->actingAs($staff, 'staff')->get($this->console.$path)->assertRedirect($setUp);
    }

    $this->actingAs($staff, 'staff')->get($setUp)->assertOk();

    $staff->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    $this->actingAs($staff->fresh(), 'staff')->get($this->console)->assertOk();
});

it('asks for the code after the password and refuses a wrong one', function () {
    $staff = staffMember();

    $login = Livewire::test(StaffLogin::class)
        ->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1'])
        ->call('authenticate')
        ->assertSee('Enter your 2FA code');

    expect(auth('staff')->check())->toBeFalse();

    $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    expect(auth('staff')->check())->toBeFalse();

    $login->set('data.multiFactor.app.code', staffOtp($staff))->call('authenticate')->assertHasNoErrors();
    expect(auth('staff')->id())->toBe($staff->id);
});

it('gives the same error for an unknown email and a wrong password', function () {
    staffMember();

    $errors = collect([
        ['email' => 'ada@goproperty.ng', 'password' => 'wrong-password'],
        ['email' => 'nobody@goproperty.ng', 'password' => 'wrong-password'],
    ])->map(fn (array $form) => Livewire::test(StaffLogin::class)->fillForm($form)->call('authenticate')->errors()->toArray());

    expect($errors->unique()->count())->toBe(1)
        ->and($errors->first())->toHaveKey('data.email');
});

it('rate limits staff sign-in attempts', function () {
    staffMember();

    foreach (range(1, 5) as $attempt) {
        Livewire::test(StaffLogin::class)->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'wrong-password'])->call('authenticate');
    }

    Livewire::test(StaffLogin::class)
        ->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1'])
        ->call('authenticate')
        ->assertNotified();

    expect(auth('staff')->check())->toBeFalse();
});

it('signs staff out after 30 idle minutes', function () {
    $staff = staffMember();

    $this->actingAs($staff, 'staff')
        ->withSession([StaffIdleTimeout::SESSION_KEY => now()->subMinutes(10)->getTimestamp()])
        ->get($this->console)->assertOk()
        ->assertSee('id="gp-idle"', false)
        ->assertSee(json_encode(route('filament.console.session-ping')), false);

    $this->actingAs($staff, 'staff')
        ->withSession([StaffIdleTimeout::SESSION_KEY => now()->subMinutes(31)->getTimestamp()])
        ->get($this->console)->assertRedirect($this->console.'/login');

    expect(auth('staff')->check())->toBeFalse();
});

it('keeps the session alive from the idle warning', function () {
    $this->actingAs(staffMember(), 'staff')
        ->get(route('filament.console.session-ping'))->assertNoContent();

    expect(session(StaffIdleTimeout::SESSION_KEY))->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

it('answers a plain 404 outside a staff member\'s IP allowlist', function () {
    $staff = staffMember();
    $staff->forceFill(['ip_allowlist' => ['10.0.0.0/8', '198.51.100.7']])->save();

    $this->actingAs($staff, 'staff')->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get($this->console)->assertOk();
    $this->actingAs($staff, 'staff')->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get($this->console)->assertOk();

    $this->actingAs($staff, 'staff')->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get($this->console)
        ->assertNotFound()
        ->assertContent('Not Found')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect(auth('staff')->check())->toBeFalse();
});

describe('sign-in security settings', function () {
    beforeEach(function () {
        $this->staff = staffMember();
        $this->actingAs($this->staff, 'staff');
    });

    it('saves the settings, which apply at once', function () {
        Livewire::test(SignInSecuritySettings::class)
            ->fillForm(['auth' => ['passkeys_enabled' => false, 'turnstile_mode' => 'always', 'lockout_after' => 7, 'lockout_minutes' => 30, 'remember_days' => 14, 'trust_device_days' => 7, 'require_2fa_for' => 'all_owners']])
            ->callAction('save')
            ->assertNotified('Saved and applied');

        $settings = app(SettingsStore::class);
        expect($settings->get('auth.passkeys_enabled'))->toBeFalse()
            ->and($settings->get('auth.turnstile_mode'))->toBe('always')
            ->and($settings->get('auth.lockout_after'))->toBe(7)
            ->and($settings->get('auth.remember_days'))->toBe(14)
            ->and($settings->get('auth.require_2fa_for'))->toBe('all_owners');

        $this->get('/login')->assertDontSee('data-passkey-block', false);
    });

    it('refuses values outside the rules', function () {
        Livewire::test(SignInSecuritySettings::class)
            ->fillForm(['auth' => ['lockout_after' => 1]])
            ->callAction('save')
            ->assertHasFormErrors(['auth.lockout_after']);
    });

    it('never sends the Turnstile secret back, and changing it needs step-up', function () {
        app(SettingsStore::class)->set('auth.turnstile_secret_key', '0x4AAAAAAAold-secret');

        $this->get($this->console.'/settings/sign-in-security')->assertOk()
            ->assertSee('•••• set')
            ->assertDontSee('old-secret');

        $page = Livewire::test(SignInSecuritySettings::class)->fillForm(['new_turnstile_secret' => '0x4AAAAAAAnew-secret']);
        $page->callAction('save', data: ['password' => 'wrong-password', 'code' => '000000'])->assertNotified('The password or code is not right.');
        expect(app(SettingsStore::class)->get('auth.turnstile_secret_key'))->toBe('0x4AAAAAAAold-secret');

        $page->callAction('save', data: ['password' => 'x-long-password-1', 'code' => staffOtp($this->staff)])->assertNotified('Saved and applied');
        expect(app(SettingsStore::class)->get('auth.turnstile_secret_key'))->toBe('0x4AAAAAAAnew-secret');

        // Saving other settings leaves the secret alone.
        Livewire::test(SignInSecuritySettings::class)->fillForm(['auth' => ['remember_days' => 20]])->callAction('save');
        expect(app(SettingsStore::class)->get('auth.turnstile_secret_key'))->toBe('0x4AAAAAAAnew-secret');

        Livewire::test(SignInSecuritySettings::class)->fillForm(['clear_turnstile_secret' => true])->callAction('save')->assertNotified('Saved and applied');
        expect(app(SettingsStore::class)->get('auth.turnstile_secret_key'))->toBeNull();
    });
});

it('does not offer "remember me" to staff', function () {
    Livewire::test(StaffLogin::class)->assertFormFieldDoesNotExist('remember');
});
