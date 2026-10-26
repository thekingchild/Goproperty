<?php

declare(strict_types=1);

use App\Audit\AccessLog;
use App\Audit\AuthenticationLog;
use App\Auth\SignIn\Turnstile;
use App\Models\User;
use App\Platform\Filament\Auth\StaffLogin;
use App\Settings\SettingsStore;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P00-11: the authentication log for customers and staff
 * (PLATFORM_CONSOLE.md § 8): logins and failures for both guards, plus
 * logout, 2FA, recovery codes, Turnstile, lockout and password resets.
 */

beforeEach(function () {
    Notification::fake();
});

/** @return list<array{string, string, int|null}> */
function authLog(): array
{
    return AuthenticationLog::query()->orderBy('id')->get()
        ->map(fn (AuthenticationLog $row): array => [$row->event, $row->authenticatable_type, $row->authenticatable_id])
        ->all();
}

it('records customer sign-ins, failures and sign-outs', function () {
    $user = User::factory()->create(['email' => 'tola@adeyemirealty.ng', 'phone' => '+2348031234567']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Firefox', 'HTTP_CF_IPCOUNTRY' => 'NG'])
        ->post('/login', ['identifier' => '0803 123 4567', 'password' => 'wrong-password']);
    $this->post('/login', ['identifier' => 'nobody@example.com', 'password' => 'wrong-password']);
    $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password']);
    $this->post(route('logout'));

    expect(authLog())->toBe([
        ['login_failed', 'user', $user->id],
        ['login_failed', 'user', null],
        ['login_success', 'user', $user->id],
        ['logout', 'user', $user->id],
    ]);

    $rows = AuthenticationLog::query()->orderBy('id')->get();
    $log = app(AccessLog::class);
    expect($rows[0]->attempted_identifier)->toBe($log->hashIdentifier('+2348031234567'))
        ->and($rows[0]->ip)->toBe('203.0.113.7')
        ->and($rows[0]->user_agent)->toBe('Firefox')
        ->and($rows[0]->location)->toBe('NG')
        ->and($rows[1]->attempted_identifier)->toBe($log->hashIdentifier('nobody@example.com'))
        ->and($rows[1]->attempted_identifier)->not->toContain('nobody')
        ->and($rows[2]->is_new_device)->toBeTrue();
});

it('records the 2FA step and recovery codes', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => '000000']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'abcde-12345']);

    expect(array_column(authLog(), 0))->toBe(['2fa_failed', 'recovery_code_used', '2fa_passed', 'login_success']);
});

it('records a failed bot check and the start of a pause', function () {
    $user = User::factory()->create(['email' => 'tola@adeyemirealty.ng']);

    foreach (range(1, 3) as $attempt) {
        $this->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'wrong-password']);
    }

    expect(array_column(authLog(), 0))->toBe(['login_failed', 'login_failed', 'login_failed', 'lockout']);

    $settings = app(SettingsStore::class);
    $settings->set('auth.turnstile_site_key', 'site');
    $settings->set('auth.turnstile_secret_key', 'secret');
    $settings->set('auth.turnstile_mode', 'always');
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => false])]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->post('/login', ['identifier' => 'tola@adeyemirealty.ng', 'password' => 'password']);

    expect(authLog()[4])->toBe(['turnstile_failed', 'user', $user->id]);
});

it('records reset links sent and passwords reset', function () {
    $user = User::factory()->create(['email' => 'tola@adeyemirealty.ng']);

    $this->post(route('password.email'), ['identifier' => 'tola@adeyemirealty.ng']);
    $this->post(route('password.email'), ['identifier' => 'nobody@example.com']);

    $token = app('auth.password.broker')->createToken($user);
    $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'long-enough-pw', 'password_confirmation' => 'long-enough-pw']);

    expect(authLog())->toBe([
        ['reset_link_sent', 'user', $user->id],
        ['password_reset', 'user', $user->id],
    ]);
});

it('records staff sign-ins, failures, the 2FA step and sign-outs', function () {
    Filament::setCurrentPanel('console');
    $staff = staffMember();

    Livewire::test(StaffLogin::class)->fillForm(['email' => 'nobody@goproperty.ng', 'password' => 'wrong-password'])->call('authenticate');
    Livewire::test(StaffLogin::class)->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'wrong-password'])->call('authenticate');

    $login = Livewire::test(StaffLogin::class)->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1'])->call('authenticate');
    $login->set('data.multiFactor.app.code', '000000')->call('authenticate');
    $login->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret))->call('authenticate');

    auth('staff')->logout();

    expect(authLog())->toBe([
        ['login_failed', 'platform_staff', null],
        ['login_failed', 'platform_staff', $staff->id],
        ['2fa_failed', 'platform_staff', $staff->id],
        ['login_success', 'platform_staff', $staff->id],
        ['2fa_passed', 'platform_staff', $staff->id],
        ['logout', 'platform_staff', $staff->id],
    ]);

    expect(AuthenticationLog::query()->first()->attempted_identifier)->toBe(app(AccessLog::class)->hashIdentifier('nobody@goproperty.ng'));
});

it('keeps the authentication log for one year', function () {
    $user = User::factory()->create();
    $log = app(AccessLog::class);

    $this->travel(-13)->months();
    $log->record('login_success', $user);
    $this->travelBack();
    $log->record('login_success', $user);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'authentication-log-prune');
    $event->run(app());

    expect(AuthenticationLog::query()->count())->toBe(1);
});
