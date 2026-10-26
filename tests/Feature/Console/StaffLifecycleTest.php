<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Notifications\StaffInvitation;
use App\Platform\Filament\Auth\AcceptStaffInvitation;
use App\Platform\Filament\Auth\StaffLogin;
use App\Platform\Filament\Resources\Staff\Pages\ListStaff;
use App\Platform\Filament\Resources\Staff\Pages\ViewStaff;
use App\Platform\Filament\Resources\Staff\RelationManagers\HistoryRelationManager;
use App\Platform\Staff\StaffChangeRefused;
use App\Platform\Staff\StaffLifecycle;
use App\Platform\Staff\StaffRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P01-07: staff lifecycle and the Staff pages (PLATFORM_CONSOLE.md § 1):
 * invite by email → set password + 2FA on first sign-in → active;
 * deactivation ends every session; at least two active super-admins; every
 * change audited and shown in the profile history.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->ada = staffNamed('Ada', ['super-admin']);
    $this->lifecycle = app(StaffLifecycle::class);
    $this->console = '/'.config('app.admin_path');
});

function invitationToken(PlatformStaff $invitee): string
{
    $token = '';

    Notification::assertSentTo($invitee, StaffInvitation::class, function (StaffInvitation $notification) use ($invitee, &$token): bool {
        $token = basename((string) parse_url((string) $notification->toMail($invitee)->actionUrl, PHP_URL_PATH));

        return true;
    });

    return $token;
}

describe('invitations', function () {
    beforeEach(fn () => Notification::fake());

    it('invites from the Staff page, with two-person roles waiting for approval', function () {
        $this->actingAs($this->ada, 'staff');
        $roles = StaffRole::query()->whereIn('name', ['support-agent', 'finance-admin'])->pluck('id')->all();

        Livewire::test(ListStaff::class)
            ->callAction('invite', data: ['name' => 'Kemi Bello', 'email' => 'Kemi@GoProperty.ng', 'roles' => $roles, 'reason' => 'New finance lead'])
            ->assertHasNoActionErrors()
            ->assertNotified('Invitation sent to kemi@goproperty.ng; 1 role grant(s) wait for approval.');

        $kemi = PlatformStaff::query()->where('email', 'kemi@goproperty.ng')->sole();

        expect($kemi->status)->toBe(StaffStatus::Invited)
            ->and($kemi->invited_by_staff_id)->toBe($this->ada->id)
            ->and($kemi->getRoleNames()->all())->toBe(['support-agent'])
            ->and(ApprovalRequest::query()->sole()->summary)->toContain('finance-admin')
            ->and(AuditEntry::query()->where('event', 'staff.invited')->where('subject_id', $kemi->id)->exists())->toBeTrue();

        Notification::assertSentTo($kemi, StaffInvitation::class);
    });

    it('refuses a second account for the same email', function () {
        $this->lifecycle->invite($this->ada, 'Kemi', 'kemi@goproperty.ng');

        expect(fn () => $this->lifecycle->invite($this->ada, 'Kemi again', 'KEMI@goproperty.ng'))->toThrow(ValidationException::class);
    });

    it('does not let staff without the permission invite', function () {
        $support = staffNamed('Sade', ['support-agent']);
        $this->actingAs($support, 'staff');

        expect(fn () => $this->lifecycle->invite($support, 'Kemi', 'kemi@goproperty.ng'))->toThrow(StaffChangeRefused::class);
        $this->get($this->console.'/staff')->assertForbidden();
    });

    it('stores only a hash of the token, and the link works once', function () {
        [$kemi] = $this->lifecycle->invite($this->ada, 'Kemi', 'kemi@goproperty.ng');
        $token = invitationToken($kemi);

        expect($kemi->fresh()?->invitation_token_hash)->toBe(hash('sha256', $token))
            ->and(PlatformStaff::query()->where('invitation_token_hash', $token)->exists())->toBeFalse();

        $this->lifecycle->acceptInvitation($token, 'a-new-Long-password-1');

        expect(fn () => $this->lifecycle->acceptInvitation($token, 'another-Long-password-2'))->toThrow(StaffChangeRefused::class);
        $this->get(route('filament.console.auth.staff-invitation', ['token' => $token]))->assertOk()->assertSee('not valid any more');
    });

    it('refuses an expired, replaced or unknown link', function () {
        [$kemi] = $this->lifecycle->invite($this->ada, 'Kemi', 'kemi@goproperty.ng');
        $first = invitationToken($kemi);

        $this->lifecycle->resendInvitation($this->ada, $kemi);
        expect($this->lifecycle->findInvitation($first))->toBeNull();

        $this->travel(8)->days();
        $this->get(route('filament.console.auth.staff-invitation', ['token' => 'not-a-real-token']))->assertSee('not valid any more');

        Livewire::test(AcceptStaffInvitation::class, ['token' => $first])->assertSet('valid', false);
    });

    it('asks for a password of 12 characters or more', function () {
        [$kemi] = $this->lifecycle->invite($this->ada, 'Kemi', 'kemi@goproperty.ng');

        Livewire::test(AcceptStaffInvitation::class, ['token' => invitationToken($kemi)])
            ->fillForm(['password' => 'short-1', 'passwordConfirmation' => 'short-1'])
            ->call('accept')
            ->assertHasFormErrors(['password']);

        expect($kemi->fresh()?->invitation_accepted_at)->toBeNull();
    });
});

describe('deactivation', function () {
    it('ends the staff member\'s sessions at their next request and stops sign-in', function () {
        $bola = staffNamed('Bola', ['support-agent']);

        $this->actingAs($bola, 'staff')->get($this->console)->assertOk();

        $this->lifecycle->deactivate($this->ada, $bola, 'Left the company');

        $this->actingAs($bola->fresh(), 'staff')->get($this->console)->assertRedirect($this->console.'/login');
        expect(auth('staff')->check())->toBeFalse();

        Livewire::test(StaffLogin::class)
            ->fillForm(['email' => 'bola@goproperty.ng', 'password' => 'x-long-password-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        expect($bola->fresh()?->status)->toBe(StaffStatus::Deactivated)
            ->and($bola->fresh()?->deactivated_at)->not->toBeNull()
            ->and(AuditEntry::query()->where('event', 'staff.deactivated')->where('subject_id', $bola->id)->sole()->causer_id)->toBe($this->ada->id);
    });

    it('is offered on the profile and refused for your own account', function () {
        $bola = staffNamed('Bola', ['analyst']);
        $this->actingAs($this->ada, 'staff');

        Livewire::test(ViewStaff::class, ['record' => $this->ada->id])->assertActionHidden('deactivate');
        expect(fn () => $this->lifecycle->deactivate($this->ada, $this->ada))->toThrow(StaffChangeRefused::class);

        Livewire::test(ViewStaff::class, ['record' => $bola->id])
            ->callAction('deactivate', data: ['reason' => 'Contract ended'])
            ->assertNotified('Deactivated. Their sessions have ended.');

        expect($bola->fresh()?->status)->toBe(StaffStatus::Deactivated);

        Livewire::test(ViewStaff::class, ['record' => $bola->id])
            ->assertActionHidden('deactivate')
            ->callAction('reactivate', data: ['reason' => 'Back'])
            ->assertNotified('Reactivated.');

        expect($bola->fresh()?->status)->toBe(StaffStatus::Active);
    });
});

describe('at least two active super-admins', function () {
    it('never leaves none while only one exists, and warns about it', function () {
        $roles = app(StaffRoles::class);
        $superAdmin = StaffRole::query()->where('name', 'super-admin')->firstOrFail();

        expect(fn () => $roles->revoke($this->ada, $this->ada, $superAdmin))->toThrow(StaffChangeRefused::class, 'only active super-admin')
            ->and($this->ada->fresh()?->isSuperAdmin())->toBeTrue();

        $this->actingAs($this->ada, 'staff')->get($this->console)->assertSee('Only one active super-admin');
    });

    it('never drops below two once two exist', function () {
        $roles = app(StaffRoles::class);
        $superAdmin = StaffRole::query()->where('name', 'super-admin')->firstOrFail();
        $bola = staffNamed('Bola', ['super-admin']);

        $this->actingAs($this->ada, 'staff')->get($this->console)->assertDontSee('Only one active super-admin');

        expect(fn () => $roles->revoke($this->ada, $bola, $superAdmin))->toThrow(StaffChangeRefused::class, 'At least two active super-admins')
            ->and(fn () => $this->lifecycle->deactivate($this->ada, $bola))->toThrow(StaffChangeRefused::class, 'At least two active super-admins')
            ->and($bola->fresh()?->status)->toBe(StaffStatus::Active);

        $chidi = staffNamed('Chidi', ['super-admin']);

        $this->lifecycle->deactivate($this->ada, $bola);
        expect(fn () => $roles->revoke($this->ada, $chidi, $superAdmin))->toThrow(StaffChangeRefused::class);

        // Other staff are not limited by the floor.
        $analyst = staffNamed('Dayo', ['analyst']);
        $this->lifecycle->deactivate($this->ada, $analyst);
        expect($analyst->fresh()?->status)->toBe(StaffStatus::Deactivated);
    });
});

it('shows the profile with roles, status, last login, 2FA and the history', function () {
    $kemi = staffNamed('Kemi', ['support-agent'], withTwoFactor: false);
    app(StaffRoles::class)->grant($this->ada, $kemi, StaffRole::query()->where('name', 'analyst')->firstOrFail(), 'Needs reports');

    $this->actingAs($this->ada, 'staff');
    $this->get($this->console.'/staff')->assertOk()->assertSee('Kemi')->assertSee('support-agent');

    Livewire::test(ViewStaff::class, ['record' => $kemi->id])
        ->assertSee('kemi@goproperty.ng')
        ->assertSee('analyst')
        ->assertSee('Never');

    Livewire::test(HistoryRelationManager::class, ['ownerRecord' => $kemi, 'pageClass' => ViewStaff::class])
        ->assertSee('staff.role_granted')
        ->assertSee('role analyst granted to kemi@goproperty.ng')
        ->assertSee('Needs reports')
        ->assertSee('Ada');
});

it('records the last staff sign-in', function () {
    Livewire::test(StaffLogin::class)
        ->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1'])
        ->call('authenticate')
        ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp((string) $this->ada->two_factor_secret))
        ->call('authenticate');

    expect($this->ada->fresh()?->last_login_at)->not->toBeNull();
});

it('edits details and the IP allowlist, and refuses a bad entry', function () {
    $bola = staffNamed('Bola', ['analyst']);
    $this->actingAs($this->ada, 'staff');

    Livewire::test(ViewStaff::class, ['record' => $bola->id])
        ->callAction('editDetails', data: ['name' => 'Bola Ade', 'phone' => '+2348012345678', 'ip_allowlist' => ['10.0.0.0/8', '198.51.100.7']])
        ->assertNotified('Details saved.');

    expect($bola->fresh()?->name)->toBe('Bola Ade')
        ->and($bola->fresh()?->ip_allowlist)->toBe(['10.0.0.0/8', '198.51.100.7'])
        ->and(AuditEntry::query()->where('event', 'staff.updated')->where('subject_id', $bola->id)->exists())->toBeTrue();

    expect(fn () => $this->lifecycle->updateDetails($this->ada, $bola, 'Bola', null, ['not-an-ip']))->toThrow(ValidationException::class);
});
