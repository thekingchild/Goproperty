<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use App\Audit\AuditEntry;
use App\Models\ApprovalRequest;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserDevice;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Customers\CustomerUser;
use App\Platform\Filament\Resources\CustomerUsers\Pages\ViewCustomerUser;
use App\Platform\Filament\Resources\ImpersonationSessions\Pages\ListImpersonationSessions;
use App\Platform\Filament\Resources\ImpersonationSessions\Pages\ViewImpersonationSession;
use App\Platform\Filament\Resources\ImpersonationSessions\RelationManagers\TimelineRelationManager;
use App\Platform\Impersonation\HandOff;
use App\Platform\Impersonation\ImpersonationEndReason;
use App\Platform\Impersonation\ImpersonationMode;
use App\Platform\Impersonation\ImpersonationRefused;
use App\Platform\Impersonation\Impersonations;
use App\Platform\Staff\StaffLifecycle;
use App\Theme\ThemeContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/impersonation.php';

/*
 * P01-08 (PLATFORM_CONSOLE.md § 6): who may impersonate, the one-time
 * hand-off into a separate 30-minute customer-side session, the banner, the
 * end (manual, timeout, by a super-admin), the hash-chained audit trail
 * with both identities and the console's impersonation log, and no email
 * or notification to the organization or the user. The blocked actions are
 * in ImpersonationGuardTest.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    seedOrganizationRoles();
    $this->console = '/'.config('app.admin_path');

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    $this->support = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng'], roles: ['support-agent']);
});

afterEach(fn () => freshRequest());

function impersonationEvents(ImpersonationSession $session): array
{
    return $session->timeline()->orderBy('id')->pluck('event')->all();
}

describe('who may start a session', function () {
    it('refuses staff without the impersonate permission, and records the attempt', function (array $roles) {
        $staff = staffNamed('Sade', $roles);

        expect(fn () => startSupport($staff, $this->owner, $this->acme))
            ->toThrow(ImpersonationRefused::class, 'You do not have permission to impersonate users.');

        expect(ImpersonationSession::query()->count())->toBe(0);

        $refused = AuditEntry::query()->where('log_name', 'impersonation')->where('event', 'impersonation.refused')->sole();
        expect($refused->causer_id)->toBe($this->owner->id)
            ->and($refused->causer_type)->toBe($this->owner->getMorphClass())
            ->and($refused->impersonator_staff_id)->toBe($staff->id)
            ->and($refused->getProperty('organization_id'))->toBe($this->acme->id)
            ->and($refused->getProperty('refusal'))->toBe('You do not have permission to impersonate users.');

        // The console does not offer it (staff who can open the user's page).
        if (in_array($roles[0] ?? null, ['finance-admin', 'compliance-officer'], true)) {
            $this->actingAs($staff, 'staff');
            Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])
                ->assertActionHidden('impersonate')
                ->assertActionHidden('startApprovedWrite');
        }
    })->with([
        'finance-admin' => [['finance-admin']],
        'compliance-officer' => [['compliance-officer']],
        'analyst' => [['analyst']],
        'technical-admin' => [['technical-admin']],
        'content-manager' => [['content-manager']],
        'no role' => [[]],
    ]);

    it('refuses write mode without impersonate.write, even to a read-only impersonator', function () {
        expect(fn () => app(Impersonations::class)->requestWrite($this->support, $this->owner, $this->acme, 'Fix phone'))
            ->toThrow(ImpersonationRefused::class, 'You do not have permission to impersonate in write mode.')
            ->and(fn () => startSupport($this->support, $this->owner, $this->acme, ImpersonationMode::Write, approval: new ApprovalRequest))
            ->toThrow(ImpersonationRefused::class, 'write mode');

        expect(ApprovalRequest::query()->count())->toBe(0)
            ->and(AuditEntry::query()->where('event', 'impersonation.refused')->count())->toBe(2);
    });

    it('starts a read-only session from the user\'s page for each role that holds the permission', function (string $role) {
        $staff = staffNamed('Tunde', [$role]);
        $this->actingAs($staff, 'staff');

        $page = Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])
            ->assertActionVisible('impersonate')
            ->callAction('impersonate', data: ['organization' => $this->acme->id, 'mode' => 'read_only', 'reason' => 'Ticket #77'])
            ->assertHasNoActionErrors();

        $url = $page->effects['redirect'] ?? null;
        expect($url)->toBeString()->toContain('/impersonation/enter/')->toContain('signature=');

        $session = ImpersonationSession::query()->sole();
        expect($session->staff_id)->toBe($staff->id)
            ->and($session->user_id)->toBe($this->owner->id)
            ->and($session->organization_id)->toBe($this->acme->id)
            ->and($session->mode)->toBe(ImpersonationMode::ReadOnly)
            ->and($session->reason)->toBe('Ticket #77')
            ->and($session->expires_at->diffInMinutes($session->started_at, true))->toEqual(30.0);

        freshRequest();
        $this->get($url)->assertRedirect('/o/acme-homes');
    })->with(['support-agent', 'operations-manager', 'super-admin']);

    it('needs a reason, a member of the organization, and never a staff account', function () {
        $stranger = Organization::factory()->create(['name' => 'Other Realty']);
        $staffLike = joinAs(User::factory()->create(['email' => 'Bola@GoProperty.ng']), $this->acme);
        staffNamed('Bola', ['support-agent']);

        expect(fn () => startSupport($this->support, $this->owner, $this->acme, reason: '  '))->toThrow(ImpersonationRefused::class, 'Give a reason')
            ->and(fn () => startSupport($this->support, $this->owner, $stranger))->toThrow(ImpersonationRefused::class, 'is not a member of Other Realty')
            ->and(fn () => startSupport($this->support, $staffLike, $this->acme))->toThrow(ImpersonationRefused::class, 'Staff accounts cannot be impersonated.');

        expect(ImpersonationSession::query()->count())->toBe(0);
    });
});

describe('the hand-off and the customer-side session', function () {
    it('opens a separate customer-side session through a one-time link valid 60 seconds', function () {
        $handOff = startSupport($this->support, $this->owner, $this->acme);

        // A customer's "remember me" in this browser is dropped with the rest.
        enterSupport($handOff)
            ->assertRedirect('/o/acme-homes')
            ->assertCookieExpired(auth()->guard('web')->getRecallerName());

        // The staff member acts as the user without signing in as them.
        expect(session()->get('impersonation.session_id'))->toBe($handOff->session->id)
            ->and(collect(session()->all())->keys()->filter(fn (string $key): bool => str_starts_with($key, 'login_web_'))->all())->toBe([])
            ->and(UserDevice::query()->count())->toBe(0);

        asSupport()->get('/o/acme-homes')->assertOk()->assertSee('Acme Homes');

        // The link works once.
        freshRequest();
        $this->get($handOff->url)->assertForbidden()->assertSee('This support link was already used.');

        expect(AuditEntry::query()->where('event', 'impersonation.refused')->where('subject_id', $handOff->session->id)->count())->toBe(1);
    });

    it('refuses a link after 60 seconds, a changed token and a broken signature', function () {
        $late = startSupport($this->support, $this->owner, $this->acme);
        $this->travel(61)->seconds();
        enterSupport($late)->assertForbidden()->assertSee('This support link has expired.');
        $this->travelBack();

        $tampered = startSupport($this->support, $this->owner, $this->acme);
        enterSupport(new HandOff($tampered->session, str_replace('token=', 'token=x', $tampered->url)))->assertForbidden();
        enterSupport(new HandOff($tampered->session, preg_replace('/signature=[^&]+/', 'signature=00', $tampered->url) ?? ''))->assertForbidden();

        // None of them opened anything.
        expect(ImpersonationSession::query()->whereNotNull('handed_off_at')->count())->toBe(0);
        asSupport()->get('/o/acme-homes')->assertRedirect('/login');
    });

    it('shows the support banner on every customer page', function () {
        enterSupport(startSupport($this->support, $this->owner, $this->acme));
        $product = app(ThemeContext::class)->current()->name();

        foreach (['/o/acme-homes', '/dashboard', '/settings/profile', '/settings/appearance', '/portal', '/owner', '/'] as $page) {
            asSupport()->get($page)
                ->assertOk()
                ->assertSee("{$product} support (Ada O.)", false)
                ->assertSee('is viewing this account')
                ->assertSee('End session')
                ->assertSee('Read-only');
        }
    });

    it('ends from the banner and returns to the console, leaving nothing signed in', function () {
        $handOff = startSupport($this->support, $this->owner, $this->acme);
        enterSupport($handOff);

        asSupport()->post('/impersonation/end')
            ->assertRedirect($this->console.'/customer-users/'.$this->owner->id);

        $session = $handOff->session->fresh();
        expect($session->ended_reason)->toBe(ImpersonationEndReason::Manual)
            ->and($session->ended_at)->not->toBeNull()
            ->and(session()->has('impersonation.session_id'))->toBeFalse();

        asSupport()->get('/o/acme-homes')->assertRedirect('/login');
    });

    it('ends after 30 minutes on the next request, and the scheduler closes unattended ones', function () {
        $handOff = startSupport($this->support, $this->owner, $this->acme);
        enterSupport($handOff);
        asSupport()->get('/o/acme-homes')->assertOk();

        $this->travel(29)->minutes();
        asSupport()->get('/o/acme-homes')->assertOk();

        $this->travel(2)->minutes();
        asSupport()->get('/o/acme-homes')->assertRedirect($this->console.'/customer-users/'.$this->owner->id);
        expect($handOff->session->fresh()->ended_reason)->toBe(ImpersonationEndReason::Timeout);
        asSupport()->get('/o/acme-homes')->assertRedirect('/login');

        // Nobody came back: the scheduler ends it on time; an unused link too.
        $this->travelBack();
        $unattended = startSupport($this->support, $this->owner, $this->acme);
        enterSupport($unattended);
        $unused = startSupport(staffNamed('Kemi', ['support-agent']), $this->owner, $this->acme);

        $this->travel(2)->minutes();
        expect(app(Impersonations::class)->endExpired())->toBe(1)
            ->and($unused->session->fresh()->ended_reason)->toBe(ImpersonationEndReason::Timeout)
            ->and($unattended->session->fresh()->ended_at)->toBeNull();

        $this->travel(29)->minutes();
        expect(app(Impersonations::class)->endExpired())->toBe(1)
            ->and($unattended->session->fresh()->ended_reason)->toBe(ImpersonationEndReason::Timeout);
    });

    it('ends when the staff member is deactivated or the user leaves the organization', function () {
        $handOff = startSupport($this->support, $this->owner, $this->acme);
        enterSupport($handOff);

        app(StaffLifecycle::class)->deactivate(staffMember(['name' => 'Root', 'email' => 'root@goproperty.ng']), $this->support->fresh());

        asSupport()->get('/o/acme-homes')->assertRedirect();
        expect($handOff->session->fresh()->ended_reason)->toBe(ImpersonationEndReason::Revoked);
    });

    it('allows one session per staff member: a new one ends the previous', function () {
        $first = startSupport($this->support, $this->owner, $this->acme);
        $second = startSupport($this->support, $this->owner, $this->acme);

        expect($first->session->fresh()->ended_reason)->toBe(ImpersonationEndReason::Manual)
            ->and($second->session->fresh()->ended_at)->toBeNull();
    });
});

describe('the audit trail and the impersonation log', function () {
    it('records start, end, reason, pages and actions with both identities in the hash chain, and shows them in the log', function () {
        $handOff = startSupport($this->support, $this->owner, $this->acme, reason: 'Ticket #4412: tenant cannot see the March bill');
        enterSupport($handOff);

        asSupport()->get('/o/acme-homes')->assertOk();
        asSupport()->get('/settings/profile')->assertOk();
        asSupport()->post('/organizations/acme-homes/switch')->assertForbidden(); // read-only: refused and recorded
        asSupport()->post('/impersonation/end')->assertRedirect();

        $session = $handOff->session->fresh();
        expect(impersonationEvents($session))->toBe([
            'impersonation.started',
            'impersonation.entered',
            'impersonation.page_viewed',
            'impersonation.page_viewed',
            'impersonation.blocked',
            'impersonation.ended',
        ]);

        foreach ($session->timeline()->get() as $entry) {
            expect($entry->log_name)->toBe('impersonation')
                ->and($entry->causer_type)->toBe($this->owner->getMorphClass())
                ->and($entry->causer_id)->toBe($this->owner->id)
                ->and($entry->impersonator_staff_id)->toBe($this->support->id)
                ->and($entry->subject_id)->toBe($session->id)
                ->and($entry->getProperty('session_id'))->toBe($session->id)
                ->and($entry->getProperty('organization_id'))->toBe($this->acme->id)
                ->and($entry->getProperty('ip'))->toBe('127.0.0.1')
                ->and($entry->getProperty('user_agent'))->toBe('Symfony')
                ->and($entry->hash)->not->toBeNull();
        }

        $started = $session->timeline()->where('event', 'impersonation.started')->sole();
        $viewed = $session->timeline()->where('event', 'impersonation.page_viewed')->orderBy('id')->pluck('properties')->map(fn ($p) => $p['path'])->all();
        $ended = $session->timeline()->where('event', 'impersonation.ended')->sole();
        expect($started->getProperty('reason'))->toBe('Ticket #4412: tenant cannot see the March bill')
            ->and($viewed)->toBe(['/o/acme-homes', '/settings/profile'])
            ->and($ended->getProperty('ended_reason'))->toBe('manual');

        expect(app(AuditChain::class)->verify()->intact)->toBeTrue();
        $this->artisan('goproperty:audit-verify')->assertExitCode(0);

        // The console's impersonation log shows the session and its timeline.
        $this->actingAs(staffNamed('Tunde', ['operations-manager']), 'staff');
        Livewire::test(ListImpersonationSessions::class)->assertCanSeeTableRecords([$session]);
        Livewire::test(ViewImpersonationSession::class, ['record' => $session->id])
            ->assertSee('Ada Okafor')
            ->assertSee('Ngozi Owner')
            ->assertSee('Acme Homes')
            ->assertSee('Ticket #4412: tenant cannot see the March bill')
            ->assertSee('Ended by the staff member');
        Livewire::test(TimelineRelationManager::class, ['ownerRecord' => $session, 'pageClass' => ViewImpersonationSession::class])
            ->assertCanSeeTableRecords($session->timeline()->get())
            ->assertSee('/o/acme-homes')
            ->assertSee('/settings/profile')
            ->assertSee('Page viewed')
            ->assertSee('Blocked');
    });

    it('records actions taken in a write-mode session, with the approval, and lists business changes in the timeline', function () {
        $admin = staffNamed('Femi', ['super-admin']);
        $handOff = startSupport($admin, $this->owner, $this->acme, ImpersonationMode::Write);
        enterSupport($handOff)->assertRedirect('/o/acme-homes');

        asSupport()->post('/organizations/acme-homes/switch')->assertRedirect('/o/acme-homes');
        asSupport()->post('/impersonation/end');

        $session = $handOff->session->fresh();
        $action = $session->timeline()->where('event', 'impersonation.action')->sole();
        expect($action->getProperty('path'))->toBe('/organizations/acme-homes/switch')
            ->and($action->getProperty('status'))->toBe(302)
            ->and($session->approvalRequest?->approvedBy?->name)->toBe('Bola Approver');

        $this->actingAs($admin, 'staff');
        Livewire::test(ViewImpersonationSession::class, ['record' => $session->id])
            ->assertSee('Approval (two-person rule)')
            ->assertSee('Bola Approver')
            ->assertSee('OK for 30 minutes');
    });

    it('filters the log by staff member, organization, user, mode and date, and audits every read', function () {
        $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
        $tenant = joinAs(User::factory()->create(['name' => 'Kunle Agent']), $other);
        $kemi = staffNamed('Kemi', ['support-agent']);

        $first = startSupport($this->support, $this->owner, $this->acme)->session;
        $second = startSupport($kemi, $tenant, $other)->session;
        $this->travel(3)->days();
        $third = startSupport(staffNamed('Femi', ['super-admin']), $this->owner, $this->acme, ImpersonationMode::Write)->session;

        $reader = staffNamed('Comfort', ['compliance-officer']);
        $this->actingAs($reader, 'staff');

        Livewire::test(ListImpersonationSessions::class)
            ->assertCanSeeTableRecords([$first, $second, $third])
            ->filterTable('staff', $kemi->id)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first, $third])
            ->resetTableFilters()
            ->filterTable('organization', $this->acme->id)->assertCanSeeTableRecords([$first, $third])->assertCanNotSeeTableRecords([$second])
            ->resetTableFilters()
            ->filterTable('user', $tenant->id)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first, $third])
            ->resetTableFilters()
            ->filterTable('mode', 'write')->assertCanSeeTableRecords([$third])->assertCanNotSeeTableRecords([$first, $second])
            ->resetTableFilters()
            ->filterTable('started', ['from' => now()->toDateString()])->assertCanSeeTableRecords([$third])->assertCanNotSeeTableRecords([$first, $second]);

        Livewire::test(ViewImpersonationSession::class, ['record' => $first->id]);

        $reads = AuditEntry::query()->where('event', 'impersonation.log_viewed')->orderBy('id')->get();
        expect($reads)->toHaveCount(2)
            ->and($reads->every(fn (AuditEntry $read): bool => $read->causer_id === $reader->id && $read->causer_type === $reader->getMorphClass()))->toBeTrue()
            ->and($reads[1]->getProperty('session_id'))->toBe($first->id);
    });

    it('opens the log only for staff with impersonation.view_log', function () {
        $session = startSupport($this->support, $this->owner, $this->acme)->session;

        foreach (['super-admin', 'operations-manager', 'compliance-officer'] as $role) {
            // Another staff member in the same browser: a fresh console session.
            $this->flushSession();
            $staff = staffNamed('Reader'.$role, [$role]);
            $this->actingAs($staff, 'staff')->get($this->console.'/impersonation-log')->assertOk();
            $this->actingAs($staff, 'staff')->get($this->console.'/impersonation-log/'.$session->id)->assertOk();
        }

        foreach (['support-agent', 'finance-admin', 'analyst', 'technical-admin'] as $role) {
            $this->flushSession();
            $staff = staffNamed('Other'.$role, [$role]);
            $this->actingAs($staff, 'staff')->get($this->console.'/impersonation-log')->assertForbidden();
            $this->actingAs($staff, 'staff')->get($this->console.'/impersonation-log/'.$session->id)->assertForbidden();
        }
    });

    it('lets a super-admin end an active session, which returns the impersonating browser to the console', function () {
        $handOff = startSupport($this->support, $this->owner, $this->acme);
        enterSupport($handOff);
        $session = $handOff->session->fresh();

        $manager = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($manager, 'staff');
        Livewire::test(ListImpersonationSessions::class)->assertTableActionHidden('end', $session);
        expect(fn () => app(Impersonations::class)->endByStaff($manager, $session))->toThrow(ImpersonationRefused::class);

        $admin = staffNamed('Femi', ['super-admin']);
        $this->actingAs($admin, 'staff');
        Livewire::test(ViewImpersonationSession::class, ['record' => $session->id])->callAction('end');

        $session->refresh();
        expect($session->ended_reason)->toBe(ImpersonationEndReason::EndedByStaff)
            ->and($session->ended_by_staff_id)->toBe($admin->id);

        asSupport()->get('/o/acme-homes')->assertRedirect($this->console.'/customer-users/'.$this->owner->id);
        asSupport()->get('/o/acme-homes')->assertRedirect('/login');
    });
});

describe('write mode needs a second staff member', function () {
    it('asks for approval, which its requester cannot give, and starts once after a second staff member approves', function () {
        $femi = staffNamed('Femi', ['super-admin']);
        $bola = staffNamed('Bola', ['super-admin']);
        $this->actingAs($femi, 'staff');

        Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])
            ->callAction('impersonate', data: ['organization' => $this->acme->id, 'mode' => 'write', 'reason' => 'Ticket #9: wrong unit numbers'])
            ->assertNoRedirect()
            ->assertActionHidden('startApprovedWrite');

        $request = ApprovalRequest::query()->sole();
        expect($request->action)->toBe(ApprovalAction::WriteImpersonation)
            ->and($request->payload)->toBe(['user_id' => $this->owner->id, 'organization_id' => $this->acme->id])
            ->and($request->reason)->toBe('Ticket #9: wrong unit numbers')
            ->and(ImpersonationSession::query()->count())->toBe(0);

        expect(fn () => app(ApprovalService::class)->approve($request, $femi))->toThrow(ApprovalRefused::class);
        // A support agent cannot approve it either (needs impersonate.write).
        expect(fn () => app(ApprovalService::class)->approve($request, $this->support))->toThrow(ApprovalRefused::class);

        app(ApprovalService::class)->approve($request, $bola, 'Go ahead');

        $page = Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])
            ->assertActionVisible('startApprovedWrite')
            ->callAction('startApprovedWrite');
        expect($page->effects['redirect'] ?? null)->toContain('/impersonation/enter/');

        $session = ImpersonationSession::query()->sole();
        expect($session->mode)->toBe(ImpersonationMode::Write)
            ->and($session->approval_request_id)->toBe($request->id)
            ->and($session->reason)->toBe('Ticket #9: wrong unit numbers');

        // The approval is used once.
        expect(fn () => startSupport($femi, $this->owner, $this->acme, ImpersonationMode::Write, approval: $request->fresh()))
            ->toThrow(ImpersonationRefused::class, 'already used');
        Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])->assertActionHidden('startApprovedWrite');
    });

    it('refuses an approval older than an hour, or one for another user', function () {
        $femi = staffNamed('Femi', ['super-admin']);
        $approval = approvedWrite($femi, $this->owner, $this->acme);
        $agent = joinAs(User::factory()->create(), $this->acme);

        expect(fn () => startSupport($femi, $agent, $this->acme, ImpersonationMode::Write, approval: $approval))
            ->toThrow(ImpersonationRefused::class, 'another session');

        $this->travel(61)->minutes();
        expect(fn () => startSupport($femi, $this->owner, $this->acme, ImpersonationMode::Write, approval: $approval))
            ->toThrow(ImpersonationRefused::class, 'expired');
    });

    it('refuses to approve once the user has left the organization', function () {
        $femi = staffNamed('Femi', ['super-admin']);
        $request = app(Impersonations::class)->requestWrite($femi, $this->owner, $this->acme, 'Ticket #10');
        $this->owner->organizations()->detach($this->acme->id);

        expect(fn () => app(ApprovalService::class)->approve($request, staffNamed('Bola', ['super-admin'])))
            ->toThrow(ApprovalRefused::class, 'no longer a member');
    });
});

it('sends no email or notification to the organization or the user across whole sessions', function () {
    Notification::fake();
    Mail::fake();

    // A known device, so a real sign-in from a new browser would email the user.
    UserDevice::query()->forceCreate(['user_id' => $this->owner->id, 'token_hash' => str_repeat('a', 64), 'ip_address' => '10.0.0.1', 'user_agent' => 'Old', 'last_seen_at' => now()->subDay()]);
    $other = joinAs(User::factory()->create(['email' => 'agent@acme.test']), $this->acme);

    // Read-only: start from the console, enter, browse, a refused write, end from the banner.
    $this->actingAs($this->support, 'staff');
    $url = Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])
        ->callAction('impersonate', data: ['organization' => $this->acme->id, 'mode' => 'read_only', 'reason' => 'Ticket #1'])
        ->effects['redirect'];
    freshRequest();
    $this->get($url)->assertRedirect();
    asSupport()->get('/o/acme-homes')->assertOk();
    asSupport()->get('/settings/profile')->assertOk();
    asSupport()->post('/email/verification-notification')->assertForbidden();
    asSupport()->post('/impersonation/end')->assertRedirect();

    // Write: request, approval, enter, an action, timeout.
    $admin = staffNamed('Femi', ['super-admin']);
    $write = startSupport($admin, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($write);
    asSupport()->post('/organizations/acme-homes/switch')->assertRedirect();
    $this->travel(31)->minutes();
    asSupport()->get('/o/acme-homes')->assertRedirect();

    // Ended by a super-admin, and an unused link closed by the scheduler.
    $this->travelBack();
    $third = startSupport($this->support, $this->owner, $this->acme);
    enterSupport($third);
    app(Impersonations::class)->endByStaff($admin, $third->session);
    startSupport($this->support, $other, $this->acme);
    $this->travel(2)->minutes();
    app(Impersonations::class)->endExpired();

    expect(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(0);
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('drops the organization support-access setting', function () {
    expect(Schema::hasColumn('organizations', 'allow_support_access'))->toBeFalse()
        ->and((new Organization)->getFillable())->not->toContain('allow_support_access')
        ->and((new Organization)->getAttributes())->not->toHaveKey('allow_support_access')
        ->and((new CustomerUser)->getTable())->toBe('users');
});
