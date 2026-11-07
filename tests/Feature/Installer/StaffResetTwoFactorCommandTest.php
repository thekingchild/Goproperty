<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\PlatformStaff;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;

/*
 * P09-07: goproperty:staff-reset-2fa, the recovery for a staff member who
 * lost their authenticator app and recovery codes (INSTALLER.md § 9,
 * docs/runbooks/install-recovery.md § 5). Command line or a one-time cron
 * job only; the next console sign-in sets up a new authenticator app.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
});

it('resets two-factor sign-in so the next console visit sets up a new authenticator app, audited', function () {
    $staff = staffMember();
    $staff->saveAppAuthenticationRecoveryCodes(['aaaaaaaaaa-bbbbbbbbbb']);
    $remember = $staff->fresh()?->remember_token;

    $this->artisan('goproperty:staff-reset-2fa', ['email' => 'ADA@goproperty.ng'])
        ->expectsConfirmation('Reset two-factor sign-in for Ada (ada@goproperty.ng)?', 'yes')
        ->expectsOutputToContain('Two-factor sign-in is reset for ada@goproperty.ng')
        ->assertSuccessful();

    $staff = $staff->fresh();
    $entry = AuditEntry::query()->where('event', 'staff.two_factor_reset')->sole();

    expect($staff?->hasTwoFactorEnabled())->toBeFalse()
        ->and($staff?->two_factor_secret)->toBeNull()
        ->and($staff?->two_factor_recovery_codes)->toBeNull()
        ->and($staff?->two_factor_confirmed_at)->toBeNull()
        ->and($staff?->remember_token)->not->toBe($remember)
        ->and($entry->log_name)->toBe('staff')
        ->and($entry->subject_id)->toBe($staff?->id)
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->properties->toArray())->toEqual(['by' => 'cli']);

    // 2FA stays mandatory: the console sends them to set up a new app.
    $this->actingAs($staff, 'staff')->get(route('filament.console.pages.dashboard'))
        ->assertRedirect(route('filament.console.auth.multi-factor-authentication.set-up-required'));
});

it('runs without a question with --force, as from a one-time cron job', function () {
    staffMember();

    Artisan::call('goproperty:staff-reset-2fa', ['email' => 'ada@goproperty.ng', '--force' => true, '--no-interaction' => true]);

    expect(PlatformStaff::query()->sole()->hasTwoFactorEnabled())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'staff.two_factor_reset')->count())->toBe(1);
});

it('changes nothing when the question is answered no, or when it cannot be asked', function () {
    staffMember();

    $this->artisan('goproperty:staff-reset-2fa', ['email' => 'ada@goproperty.ng'])
        ->expectsConfirmation('Reset two-factor sign-in for Ada (ada@goproperty.ng)?', 'no')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    $this->artisan('goproperty:staff-reset-2fa', ['email' => 'ada@goproperty.ng', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --force')
        ->assertFailed();

    expect(PlatformStaff::query()->sole()->hasTwoFactorEnabled())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'staff.two_factor_reset')->exists())->toBeFalse();
});

it('refuses an email no staff account has, and only resets the one named', function () {
    staffMember();
    $bola = staffNamed('Bola', ['analyst']);

    $this->artisan('goproperty:staff-reset-2fa', ['email' => 'nobody@goproperty.ng', '--force' => true])
        ->expectsOutputToContain('No staff account has the email nobody@goproperty.ng.')
        ->assertFailed();

    $this->artisan('goproperty:staff-reset-2fa', ['email' => 'bola@goproperty.ng', '--force' => true])->assertSuccessful();

    expect(PlatformStaff::query()->where('email', 'ada@goproperty.ng')->sole()->hasTwoFactorEnabled())->toBeTrue()
        ->and($bola->fresh()?->hasTwoFactorEnabled())->toBeFalse();
});

it('says so when there is no authenticator app to reset', function () {
    staffMember(withTwoFactor: false);

    $this->artisan('goproperty:staff-reset-2fa', ['email' => 'ada@goproperty.ng', '--force' => true])
        ->expectsOutputToContain('has no authenticator app set up')
        ->assertSuccessful();

    expect(AuditEntry::query()->where('event', 'staff.two_factor_reset')->exists())->toBeFalse();
});
