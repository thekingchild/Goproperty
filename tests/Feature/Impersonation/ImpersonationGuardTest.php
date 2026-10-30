<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\CurrentImpersonation;
use App\Tenancy\ImpersonationGuard;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Fixtures\Http\WorkspaceWriteProbe;
use Tests\Fixtures\Livewire\SupportProbe;

require_once __DIR__.'/../../Support/impersonation.php';

/*
 * P01-08 acceptance: "Impersonating staff cannot confirm a payment or view
 * bank/Paystack keys" (PLATFORM_CONSOLE.md § 6, AGENT.md § 3 rule 9). The
 * always-blocked actions are refused in both modes by App\Tenancy\
 * ImpersonationGuard on the same four paths as the suspended-organization
 * rule (HTTP, Livewire calls, permissions, policies); a read-only session
 * changes nothing at all. Each refusal is in the audit log.
 */

beforeEach(function () {
    Route::middleware(['web', 'auth', 'verified', 'organization'])->prefix('o/{organization}')->group(function () {
        Route::get('support-probe', fn () => response('<!DOCTYPE html><html><body><p>Bills page</p></body></html>'));
        // Stand-ins for the bank account / Paystack keys page (P03-08) and
        // payment confirmation (P03-10a): each checks its permission, as
        // every controller must (AGENT.md § 3 rule 4).
        Route::get('support-probe/payment-settings', fn () => response('Bank account 0123456789, Paystack sk_live_secret'))
            ->middleware('can:'.OrganizationPermission::ManagePaymentSettings->value);
        Route::post('support-probe/payments/confirm', function () {
            Cache::store('array')->increment('support.http-confirms');

            return ['confirmed' => true];
        })->middleware('can:'.OrganizationPermission::ConfirmPayments->value);
        Route::post('support-probe/rename', function (Organization $organization) {
            $organization->update(['name' => (string) request('name')]);

            return ['saved' => true];
        })->middleware('can:'.OrganizationPermission::UpdateOrganization->value);
        Route::get('support-probe/live', fn () => Blade::render('<livewire:support-probe />'));
        Route::post('support-probe/export', [WorkspaceWriteProbe::class, 'export']);
    });
    Route::getRoutes()->refreshNameLookups();
    Livewire::component('support-probe', SupportProbe::class);

    Notification::fake();
    seedStaffRoles();
    seedOrganizationRoles();
    $this->staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    joinAs($this->owner, $this->other);
});

afterEach(fn () => freshRequest());

/** Starts and enters a session as the owner; the browser now holds it. */
function supportAsOwner(ImpersonationMode $mode): ImpersonationSession
{
    $handOff = startSupport(test()->staff, test()->owner, test()->acme, $mode);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    return $handOff->session->fresh();
}

/**
 * Loads the probe page in the support session and sends one Livewire
 * update request to it, as the browser would.
 *
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 */
function supportLivewireCall(array $calls): TestResponse
{
    $page = asSupport()->get('/o/acme-homes/support-probe/live')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $match);
    expect($match)->toHaveKey(1);

    return asSupport()
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => html_entity_decode($match[1], ENT_QUOTES),
            'updates' => (object) [],
            'calls' => array_map(fn (array $call): array => $call + ['metadata' => []], $calls),
        ]]]);
}

function blockedEntries(ImpersonationSession $session): int
{
    return AuditEntry::query()->where('log_name', 'impersonation')->where('event', 'impersonation.blocked')
        ->where('subject_type', $session->getMorphClass())->where('subject_id', $session->id)->count();
}

function supportCount(string $key): int
{
    return (int) Cache::store('array')->get($key, 0);
}

it('stops impersonating staff from confirming a payment or viewing bank and Paystack keys, in both modes', function (ImpersonationMode $mode) {
    // The owner can do both themselves.
    $this->actingAs($this->owner)->get('/o/acme-homes/support-probe/payment-settings')->assertOk();
    $this->actingAs($this->owner)->postJson('/o/acme-homes/support-probe/payments/confirm')->assertOk();
    expect(supportCount('support.http-confirms'))->toBe(1);
    freshRequest();

    $session = supportAsOwner($mode);

    asSupport()->get('/o/acme-homes/support-probe/payment-settings')->assertForbidden()->assertDontSee('sk_live_secret');
    asSupport()->postJson('/o/acme-homes/support-probe/payments/confirm')->assertForbidden();
    expect(supportCount('support.http-confirms'))->toBe(1);

    // Livewire: the permission check inside the method, and a method marked
    // #[BlockedWhileImpersonating] (refused before it runs).
    supportLivewireCall([['method' => 'confirmPayment', 'params' => []]])->assertForbidden();
    supportLivewireCall([['method' => 'rotatePaystackKeys', 'params' => []]])->assertForbidden();
    expect(supportCount('support.confirms'))->toBe(0)
        ->and(supportCount('support.keys'))->toBe(0);

    // Each refusal is in the audit log with both identities.
    expect(blockedEntries($session))->toBe(4);
    $blocked = AuditEntry::query()->where('event', 'impersonation.blocked')->orderBy('id')->firstOrFail();
    expect($blocked->causer_type)->toBe($this->owner->getMorphClass())
        ->and($blocked->causer_id)->toBe($this->owner->id)
        ->and($blocked->impersonator_staff_id)->toBe($this->staff->id)
        ->and($blocked->getProperty('session_id'))->toBe($session->id)
        ->and($blocked->getProperty('path'))->toBe('/o/acme-homes/support-probe/payment-settings');
})->with([
    'read-only' => ImpersonationMode::ReadOnly,
    'write' => ImpersonationMode::Write,
]);

it('takes away exactly the always-blocked permissions in write mode, and every write in read-only mode', function () {
    $session = ImpersonationSession::query()->forceCreate([
        'staff_id' => $this->staff->id, 'user_id' => $this->owner->id, 'organization_id' => $this->acme->id,
        'reason' => 'Ticket 1', 'mode' => ImpersonationMode::Write, 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'handed_off_at' => now(),
    ]);
    $current = app(CurrentImpersonation::class);
    $current->begin($session);

    app(OrganizationContext::class)->run($this->acme, function () use ($current, $session): void {
        $owner = $this->owner->fresh();

        foreach (OrganizationPermission::cases() as $permission) {
            $expected = ! $permission->blockedWhileImpersonating();
            expect($owner->hasPermissionTo($permission->value))->toBe($expected, "write: {$permission->value}")
                ->and(Gate::forUser($owner)->allows($permission->value))->toBe($expected, "write gate: {$permission->value}");
        }

        $session->mode = ImpersonationMode::ReadOnly;
        $current->begin($session);

        foreach (OrganizationPermission::cases() as $permission) {
            $expected = ! $permission->blockedWhileImpersonating() && $permission->allowedWhileSuspended();
            expect($owner->hasPermissionTo($permission->value))->toBe($expected, "read-only: {$permission->value}");
        }
    });

    $current->clear();

    // Outside a session the owner holds everything.
    app(OrganizationContext::class)->run($this->acme, fn () => expect($this->owner->fresh()->hasPermissionTo(OrganizationPermission::ConfirmPayments->value))->toBeTrue());
});

it('classes the money, bank, Paystack, billing, compliance, member, delete, import and handover permissions as always blocked', function () {
    $blocked = array_values(array_map(
        fn (OrganizationPermission $permission): string => $permission->value,
        array_filter(OrganizationPermission::cases(), fn (OrganizationPermission $permission): bool => $permission->blockedWhileImpersonating()),
    ));

    expect($blocked)->toBe([
        'organization.delete',
        'organization.billing',
        'organization.payment_settings',
        'payment_settings.view',
        'organization.compliance',
        'members.manage',
        // Which buildings an agent sees (P02-03): a member-access change.
        'properties.assign_agents',
        // Who signs in to a tenant's account (P03-01b): invitations, sign-in links, phone confirmation.
        'tenants.portal_access',
        'bills.manage',
        'payments.confirm',
        'payments.submit_proof',
        // Bank statement uploads (P03-07a): what they bring in becomes payments once matched.
        'statements.import',
        // Statement matching (P03-07b): it records and confirms payments.
        'statements.match',
        'receipts.approve_void',
        'imports.manage',
        // Management handovers (P03-14): buildings, tenants and their credit move between organizations.
        'handovers.manage',
        // Owner statements (P08-05): the agency's commission, what an owner sees and marking a month paid to the owner.
        'owner_statements.manage',
        // The organization's own WhatsApp credentials (P05-06): access token and app secret.
        'whatsapp.connection',
    ]);
});

it('denies policy abilities that delete or void, marked policy methods, and reads only in read-only mode', function () {
    foreach (['view', 'viewBankAccount', 'exportBills', 'update', 'renew', 'delete', 'deleteAny', 'forceDelete', 'removeMember', 'voidReceipt', 'confirmBankTransfer'] as $ability) {
        Gate::define($ability, fn (): bool => true);
    }

    $policy = new class
    {
        public function update(): bool
        {
            return true;
        }

        #[BlockedWhileImpersonating]
        public function confirmBankTransfer(): bool
        {
            return true;
        }
    };
    Gate::policy(stdClass::class, $policy::class);

    $session = ImpersonationSession::query()->forceCreate([
        'staff_id' => $this->staff->id, 'user_id' => $this->owner->id, 'organization_id' => $this->acme->id,
        'reason' => 'Ticket 1', 'mode' => ImpersonationMode::Write, 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'handed_off_at' => now(),
    ]);
    $current = app(CurrentImpersonation::class);
    $current->begin($session);
    $gate = Gate::forUser($this->owner);

    // Write mode: ordinary writes pass; deleting, voiding and marked policy methods never do.
    foreach (['view', 'viewBankAccount', 'exportBills', 'update', 'renew'] as $allowed) {
        expect($gate->allows($allowed))->toBeTrue($allowed);
    }

    foreach (['delete', 'deleteAny', 'forceDelete', 'removeMember', 'voidReceipt'] as $denied) {
        expect($gate->allows($denied))->toBeFalse($denied)
            ->and($gate->inspect($denied)->message())->toBe(ImpersonationGuard::BLOCKED_MESSAGE);
    }

    expect($gate->allows('update', new stdClass))->toBeTrue()
        ->and($gate->allows('confirmBankTransfer', new stdClass))->toBeFalse();

    // Read-only: only reads.
    $session->mode = ImpersonationMode::ReadOnly;
    $current->begin($session);

    foreach (['view', 'viewBankAccount', 'exportBills'] as $read) {
        expect($gate->allows($read))->toBeTrue($read);
    }

    foreach (['update', 'renew'] as $write) {
        expect($gate->allows($write))->toBeFalse($write)
            ->and($gate->inspect($write)->message())->toBe(ImpersonationGuard::READ_ONLY_MESSAGE);
    }

    // Another organization of the same user is out of bounds.
    expect($gate->allows('view', $this->other))->toBeFalse()
        ->and($gate->allows('view', $this->acme))->toBeTrue();

    // Another user (not impersonated) is unaffected.
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    expect(Gate::forUser($agent)->allows('delete'))->toBeTrue();

    $current->clear();
    expect($gate->allows('delete'))->toBeTrue();
});

it('lets a write-mode session change business data, audited with both identities, and a read-only one change nothing', function () {
    $session = supportAsOwner(ImpersonationMode::Write);

    asSupport()->postJson('/o/acme-homes/support-probe/rename', ['name' => 'Acme Homes Ltd'])->assertOk();
    supportLivewireCall([['method' => 'save', 'params' => []]])->assertOk();
    expect($this->acme->fresh()->name)->toBe('Acme Homes Ltd')
        ->and(supportCount('support.saves'))->toBe(1);

    // The organization's own change entry names the staff member and the session.
    $change = AuditEntry::query()->where('log_name', 'organization')->where('event', 'updated')->latest('id')->firstOrFail();
    expect($change->causer_id)->toBe($this->owner->id)
        ->and($change->causer_type)->toBe($this->owner->getMorphClass())
        ->and($change->impersonator_staff_id)->toBe($this->staff->id)
        ->and($change->getProperty('impersonation_session_id'))->toBe($session->id);

    // The action itself is in the session's trail.
    expect(AuditEntry::query()->where('event', 'impersonation.action')->where('subject_id', $session->id)->count())->toBe(2);

    // Read-only: the same write is refused, through HTTP and Livewire.
    asSupport()->post('/impersonation/end')->assertRedirect();
    $readOnly = supportAsOwner(ImpersonationMode::ReadOnly);

    asSupport()->postJson('/o/acme-homes/support-probe/rename', ['name' => 'Changed'])
        ->assertForbidden()
        ->assertJsonPath('message', ImpersonationGuard::READ_ONLY_MESSAGE);
    supportLivewireCall([['method' => 'save', 'params' => []]])->assertForbidden();
    expect($this->acme->fresh()->name)->toBe('Acme Homes Ltd')
        ->and(supportCount('support.saves'))->toBe(1);

    // Reading, paging and exporting still work.
    asSupport()->get('/o/acme-homes/support-probe')->assertOk()->assertSee('Bills page');
    asSupport()->postJson('/o/acme-homes/support-probe/export')->assertOk()->assertJson(['exported' => true]);
    supportLivewireCall([['method' => 'nextPage', 'params' => []]])->assertOk();
    supportLivewireCall([['method' => 'export', 'params' => []]])->assertOk();
    expect(supportCount('support.exports'))->toBe(1)
        ->and(blockedEntries($readOnly))->toBe(2);
});

it('refuses the account security pages and sign-in details in both modes', function (ImpersonationMode $mode) {
    $session = supportAsOwner($mode);

    // The refusal page keeps the banner, so the session can still be ended from it.
    asSupport()->get('/settings/security')->assertForbidden()->assertSee('End session');
    asSupport()->get('/user/two-factor-recovery-codes')->assertForbidden();
    asSupport()->get('/user/two-factor-secret-key')->assertForbidden();
    asSupport()->post('/user/two-factor-authentication')->assertForbidden();
    asSupport()->delete('/user/two-factor-authentication')->assertForbidden();
    asSupport()->post('/user/confirm-password', ['password' => 'password'])->assertForbidden();
    asSupport()->get('/user/passkeys/options')->assertForbidden();
    asSupport()->post('/email/verification-notification')->assertForbidden();

    // The profile page opens, but its email change and verification mail are refused.
    $page = asSupport()->get('/settings/profile')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $match);
    expect($match)->toHaveKey(1);

    foreach (['updateProfileInformation', 'resendVerificationNotification'] as $method) {
        asSupport()->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                'snapshot' => html_entity_decode($match[1], ENT_QUOTES),
                'updates' => (object) ['email' => 'staff-takeover@example.com'],
                'calls' => [['method' => $method, 'params' => [], 'metadata' => []]],
            ]]])
            ->assertForbidden();
    }

    $owner = $this->owner->fresh();
    expect($owner->email)->not->toBe('staff-takeover@example.com')
        ->and($owner->two_factor_secret)->toBeNull()
        ->and(blockedEntries($session))->toBe(10);
    Notification::assertNothingSent();
})->with([
    'read-only' => ImpersonationMode::ReadOnly,
    'write' => ImpersonationMode::Write,
]);

it('refuses the password, two-factor, passkey and account deletion components', function (string $component, string $method) {
    $session = ImpersonationSession::query()->forceCreate([
        'staff_id' => $this->staff->id, 'user_id' => $this->owner->id, 'organization_id' => $this->acme->id,
        'reason' => 'Ticket 1', 'mode' => ImpersonationMode::Write, 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'handed_off_at' => now(),
    ]);
    // Livewire's test requests skip middleware: the session is set as ResumeImpersonation would.
    app(CurrentImpersonation::class)->begin($session);
    $this->actingAs($this->owner);

    $params = $component === 'pages::settings.two-factor-setup-modal' ? ['requiresConfirmation' => true] : [];

    Livewire::test($component, $params)->call($method)->assertForbidden();

    expect($this->owner->fresh()->two_factor_secret)->toBeNull()
        ->and(User::query()->whereKey($this->owner->id)->exists())->toBeTrue();
})->with([
    'change password' => ['pages::settings.security', 'updatePassword'],
    'delete passkey' => ['pages::settings.security', 'deletePasskey'],
    'enable 2FA' => ['pages::settings.two-factor-setup-modal', 'startTwoFactorSetup'],
    'new recovery codes' => ['pages::settings.two-factor.recovery-codes', 'regenerateRecoveryCodes'],
    'delete account' => ['pages::settings.delete-user-modal', 'deleteUser'],
]);

it('keeps the session to its organization', function () {
    supportAsOwner(ImpersonationMode::Write);

    asSupport()->get('/o/acme-homes')->assertOk();
    asSupport()->get('/o/other-realty')->assertNotFound();
    asSupport()->post('/organizations/other-realty/switch')->assertNotFound();
});
