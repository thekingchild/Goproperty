<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Audit\AuditLogEntry;
use App\Platform\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Platform\Filament\Resources\Staff\Pages\ViewStaff;
use App\Platform\Filament\Resources\Staff\RelationManagers\ActionsRelationManager;
use App\Platform\Filament\Resources\Staff\RelationManagers\HistoryRelationManager;
use App\Platform\Staff\StaffRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * P06-07c (PLATFORM_CONSOLE.md § 7, P06-subtasks defaults 60 and 62):
 * Console → Audit → Audit log across organizations and a staff member's
 * Actions. `audit.view` only (super-admin by default), read-only, every
 * opening audited once.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    seedOrganizationRoles();
    $this->console = '/'.config('app.admin_path');

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    $this->kemi = staffNamed('Kemi', ['operations-manager']);
    $this->tunde = staffNamed('Tunde', ['finance-admin']);
    $this->ada = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
});

/** @param  array<string, mixed>  $properties */
function auditRow(string $log, string $event, string $description, ?object $causer = null, ?object $subject = null, array $properties = [], ?int $impersonator = null): AuditEntry
{
    $logger = activity($log)->event($event)->withProperties($properties);
    $logger = $causer !== null ? $logger->causedBy($causer) : $logger->causedByAnonymous();

    if ($subject !== null) {
        $logger->performedOn($subject);
    }

    if ($impersonator !== null) {
        $logger->tap(function (mixed $entry) use ($impersonator): void {
            if ($entry instanceof AuditEntry) {
                $entry->impersonator_staff_id = $impersonator;
            }
        });
    }

    $entry = $logger->log($description);
    expect($entry)->toBeInstanceOf(AuditEntry::class);

    return $entry;
}

/** @return list<AuditEntry> */
function auditViews(): array
{
    return AuditEntry::query()->where('log_name', 'audit')->where('event', 'audit.viewed')->orderBy('id')->get()->all();
}

describe('who may open it', function () {
    it('opens the audit log and an entry for super-admins only, and answers 403 for every other default role', function () {
        $entry = auditRow('customers', 'organization.suspended', 'Suspended Acme Homes', $this->kemi, $this->acme);

        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log')->assertOk()->assertSee('Suspended Acme Homes');
        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log/'.$entry->id)->assertOk()->assertSee('Suspended Acme Homes');

        foreach (['support-agent', 'operations-manager', 'finance-admin', 'compliance-officer', 'content-manager', 'analyst', 'technical-admin'] as $role) {
            $this->flushSession();
            $staff = staffNamed('Other'.str_replace('-', '', $role), [$role]);

            $this->actingAs($staff, 'staff')->get($this->console.'/audit-log')->assertForbidden();
            $this->actingAs($staff, 'staff')->get($this->console.'/audit-log/'.$entry->id)->assertForbidden();
            expect(Gate::forUser($staff)->allows('viewAny', AuditLogEntry::class))->toBeFalse();
        }
    });

    it('follows the permission, not the role name: a custom role with audit.view opens it', function () {
        [$role] = app(StaffRoles::class)->createRole($this->ada, 'Auditors', null, ['audit.view']);
        $auditor = staffNamed('Bisi');
        app(StaffRoles::class)->grant($this->ada, $auditor, $role);

        $this->actingAs($auditor->fresh(), 'staff')->get($this->console.'/audit-log')->assertOk();
    });

    it('refuses staff without 2FA, even super-admins', function () {
        $entry = auditRow('customers', 'organization.suspended', 'Suspended Acme Homes', $this->kemi, $this->acme);
        $no2fa = staffMember(['name' => 'Yemi', 'email' => 'yemi@goproperty.ng'], withTwoFactor: false);
        $setUp = route('filament.console.auth.multi-factor-authentication.set-up-required');

        $this->actingAs($no2fa, 'staff')->get($this->console.'/audit-log')->assertRedirect($setUp);
        $this->actingAs($no2fa, 'staff')->get($this->console.'/audit-log/'.$entry->id)->assertRedirect($setUp);
        expect(Gate::forUser($no2fa)->allows('viewAny', AuditLogEntry::class))->toBeFalse()
            ->and(auditViews())->toBe([]);
    });

    it('is read-only: no create, edit or delete, by policy, by route and on the model', function () {
        $entry = AuditLogEntry::query()->findOrFail(auditRow('customers', 'organization.suspended', 'Suspended', $this->kemi, $this->acme)->id);

        expect(Gate::forUser($this->ada)->allows('create', AuditLogEntry::class))->toBeFalse()
            ->and(Gate::forUser($this->ada)->allows('update', $entry))->toBeFalse()
            ->and(Gate::forUser($this->ada)->allows('delete', $entry))->toBeFalse()
            ->and(Gate::forUser($this->ada)->allows('deleteAny', AuditLogEntry::class))->toBeFalse()
            ->and(Route::has('filament.console.resources.audit-log.edit'))->toBeFalse()
            ->and(Route::has('filament.console.resources.audit-log.create'))->toBeFalse()
            ->and(fn () => $entry->forceFill(['description' => 'Changed'])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);

        $this->actingAs($this->ada, 'staff');
        Livewire::test(ListAuditLog::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        expect(AuditEntry::query()->findOrFail($entry->id)->description)->toBe('Suspended')
            ->and(app(AuditChain::class)->verify()->intact)->toBeTrue();
    });
});

describe('what it lists', function () {
    it('lists every organization\'s entries newest first and filters by organization, by subject or properties.organization_id', function () {
        $aboutAcme = auditRow('customers', 'organization.suspended', 'Suspended Acme', $this->kemi, $this->acme);
        $acmeBill = auditRow('bills', 'bill.issued', 'Bill issued', $this->owner, null, ['organization_id' => $this->acme->id]);
        $acmeText = auditRow('settings', 'settings.changed', 'Settings changed', $this->owner, null, ['organization_id' => (string) $this->acme->id]);
        $aboutOther = auditRow('customers', 'organization.suspended', 'Suspended Other', $this->kemi, $this->other, ['organization_id' => $this->other->id]);
        $otherBill = auditRow('bills', 'bill.issued', 'Other bill', null, null, ['organization_id' => $this->other->id]);
        // Same id as Acme, another type: not about Acme.
        $sameId = auditRow('members', 'user.updated', 'A user with Acme\'s id', $this->owner, (new User)->forceFill(['id' => $this->acme->id]));

        $this->actingAs($this->ada, 'staff');

        $page = Livewire::test(ListAuditLog::class)
            ->assertCanSeeTableRecords([$sameId, $otherBill, $aboutOther, $acmeText, $acmeBill, $aboutAcme], inOrder: true)
            ->filterTable('organization', ['organization_id' => $this->acme->id])
            ->assertCanSeeTableRecords([$aboutAcme, $acmeBill, $acmeText])
            ->assertCanNotSeeTableRecords([$aboutOther, $otherBill, $sameId])
            ->resetTableFilters()
            ->filterTable('organization', ['organization_id' => $this->other->id])
            ->assertCanSeeTableRecords([$aboutOther, $otherBill])
            ->assertCanNotSeeTableRecords([$aboutAcme, $acmeBill, $acmeText, $sameId]);

        expect($page->instance()->getTableRecords()->every(fn (AuditLogEntry $row): bool => $row->organizationId() === $this->other->id))->toBeTrue();
    });

    it('filters by staff member, customer user, who did it, during a support session, log, event, dates and description', function () {
        $byKemi = auditRow('customers', 'organization.suspended', 'Suspended Acme', $this->kemi, $this->acme);
        $byTunde = auditRow('plans', 'plan.updated', 'Changed the Growth plan', $this->tunde);
        $byOwner = auditRow('bills', 'bill.issued', 'Bill issued', $this->owner, null, ['organization_id' => $this->acme->id]);
        $bySystem = auditRow('billing', 'subscription.past_due', 'Subscription past due', null, null, ['organization_id' => $this->acme->id]);
        $inSession = auditRow('leases', 'lease.updated', 'Lease edited in a support session', $this->owner, null, ['organization_id' => $this->acme->id, 'impersonation_session_id' => 7], impersonator: $this->kemi->id);
        $this->travel(3)->days();
        $later = auditRow('plans', 'plan.created', 'Created the Scale plan', $this->tunde);

        $this->actingAs($this->ada, 'staff');

        Livewire::test(ListAuditLog::class)
            ->filterTable('staff', $this->kemi->id)
            ->assertCanSeeTableRecords([$byKemi])->assertCanNotSeeTableRecords([$byTunde, $byOwner, $bySystem, $inSession, $later])
            ->resetTableFilters()
            ->filterTable('causer_kind', 'staff')
            ->assertCanSeeTableRecords([$byKemi, $byTunde, $later])->assertCanNotSeeTableRecords([$byOwner, $bySystem, $inSession])
            ->resetTableFilters()
            ->filterTable('causer_kind', 'customer')
            ->assertCanSeeTableRecords([$byOwner, $inSession])->assertCanNotSeeTableRecords([$byKemi, $byTunde, $bySystem, $later])
            ->resetTableFilters()
            ->filterTable('causer_kind', 'system')
            ->assertCanSeeTableRecords([$bySystem])->assertCanNotSeeTableRecords([$byKemi, $byTunde, $byOwner, $inSession, $later])
            ->resetTableFilters()
            ->filterTable('user', ['user_id' => $this->owner->id])
            ->assertCanSeeTableRecords([$byOwner, $inSession])->assertCanNotSeeTableRecords([$byKemi, $byTunde, $bySystem, $later])
            ->resetTableFilters()
            ->filterTable('support_session', true)
            ->assertCanSeeTableRecords([$inSession])->assertCanNotSeeTableRecords([$byKemi, $byTunde, $byOwner, $bySystem, $later])
            ->resetTableFilters()
            ->filterTable('log_name', 'plans')
            ->assertCanSeeTableRecords([$byTunde, $later])->assertCanNotSeeTableRecords([$byKemi, $byOwner, $bySystem, $inSession])
            ->resetTableFilters()
            ->filterTable('event', ['event' => 'bill.issued'])
            ->assertCanSeeTableRecords([$byOwner])->assertCanNotSeeTableRecords([$byKemi, $byTunde, $bySystem, $inSession, $later])
            ->resetTableFilters()
            ->filterTable('dates', ['from' => now('Africa/Lagos')->toDateString()])
            ->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$byKemi, $byTunde, $byOwner, $bySystem, $inSession])
            ->resetTableFilters()
            ->filterTable('dates', ['until' => now('Africa/Lagos')->subDays(3)->toDateString()])
            ->assertCanSeeTableRecords([$byKemi, $byTunde, $byOwner, $bySystem, $inSession])->assertCanNotSeeTableRecords([$later])
            ->resetTableFilters()
            ->searchTable('Growth plan')
            ->assertCanSeeTableRecords([$byTunde])->assertCanNotSeeTableRecords([$byKemi, $byOwner, $bySystem, $inSession, $later]);
    });

    it('shows an entry: when, who, impersonator, subject, reason, IP, user agent, what changed and its hash-chain link', function () {
        $previous = auditRow('customers', 'organization.created', 'Created', $this->kemi, $this->acme);
        $entry = app(AuditContext::class)->because('Customer asked by phone', fn () => auditRow('customers', 'organization.suspended', 'Suspended Acme Homes', $this->owner, $this->acme, [
            'organization_id' => $this->acme->id,
            'old' => ['status' => 'active'],
            'new' => ['status' => 'suspended'],
        ], impersonator: $this->kemi->id));
        $entry->refresh();

        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log/'.$entry->id)
            ->assertOk()
            ->assertSee('Suspended Acme Homes')
            ->assertSee('Ngozi Owner')
            ->assertSee('ngozi@acme.test')
            ->assertSee('Kemi')
            ->assertSee('Organization #'.$this->acme->id)
            ->assertSee('Acme Homes')
            ->assertSee('Customer asked by phone')
            ->assertSee('suspended')
            ->assertSee('active')
            ->assertSee((string) $entry->ip)
            ->assertSee((string) $entry->prev_hash)
            ->assertSee((string) $entry->hash);

        expect($entry->prev_hash)->toBe($previous->fresh()?->hash)
            ->and(AuditLogEntry::query()->findOrFail($entry->id)->changes())->toEqual([['field' => 'status', 'old' => 'active', 'new' => 'suspended']]);
    });

    it('escapes HTML in descriptions, properties and changes', function () {
        $entry = auditRow('settings', 'settings.changed', '<script>alert(1)</script> renamed', $this->owner, null, [
            'organization_id' => $this->acme->id,
            'note' => '<img src=x onerror=alert(2)>',
            'old' => ['name' => '<b>Old</b>'],
            'new' => ['name' => '<i>New</i>'],
        ]);

        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log/'.$entry->id)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<b>Old</b>', false)
            ->assertDontSee('<i>New</i>', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertSee('&lt;b&gt;Old&lt;/b&gt;', false)
            ->assertSee('&lt;i&gt;New&lt;/i&gt;', false);
    });
});

describe('reading it is audited', function () {
    it('writes one audit.viewed entry per page load of the list, with its filters, and none on re-renders', function () {
        auditRow('customers', 'organization.suspended', 'Suspended Acme', $this->kemi, $this->acme);

        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log?'.http_build_query(['filters' => ['support_session' => ['value' => '1']], 'search' => 'Suspended']))->assertOk();

        $views = auditViews();
        expect($views)->toHaveCount(1)
            ->and($views[0]->causer_type)->toBe((new PlatformStaff)->getMorphClass())
            ->and($views[0]->causer_id)->toBe($this->ada->id)
            ->and($views[0]->getProperty('view'))->toBe('list')
            ->and($views[0]->getProperty('filters'))->toEqual(['support_session' => ['value' => '1']])
            ->and($views[0]->getProperty('search'))->toBe('Suspended');

        Livewire::test(ListAuditLog::class)
            ->filterTable('staff', $this->kemi->id)
            ->searchTable('Acme')
            ->resetTableFilters()
            ->call('gotoPage', 2);

        expect(auditViews())->toHaveCount(2);
    });

    it('writes one audit.viewed entry naming the entry when one is opened', function () {
        $entry = auditRow('customers', 'organization.suspended', 'Suspended Acme', $this->kemi, $this->acme);

        $this->actingAs($this->ada, 'staff')->get($this->console.'/audit-log/'.$entry->id)->assertOk();

        $views = auditViews();
        expect($views)->toHaveCount(1)
            ->and($views[0]->causer_id)->toBe($this->ada->id)
            ->and($views[0]->getProperty('view'))->toBe('entry')
            ->and($views[0]->getProperty('entry_id'))->toBe($entry->id);

        $this->get($this->console.'/audit-log/'.$entry->id)->assertOk();
        expect(auditViews())->toHaveCount(2);
    });
});

describe('a staff member\'s actions', function () {
    it('lists the entries a staff member caused, beside the history about them, for audit.view only', function () {
        $byKemi = auditRow('customers', 'organization.suspended', 'Suspended Acme', $this->kemi, $this->acme);
        $alsoKemi = auditRow('customers', 'organization.reactivated', 'Reactivated Acme', $this->kemi, $this->acme);
        $aboutKemi = auditRow('staff', 'staff.role_granted', 'Granted operations-manager', $this->tunde, $this->kemi);
        $byTunde = auditRow('plans', 'plan.updated', 'Changed a plan', $this->tunde);

        $this->actingAs($this->ada, 'staff');
        expect(ActionsRelationManager::canViewForRecord($this->kemi, ViewStaff::class))->toBeTrue();

        Livewire::test(ActionsRelationManager::class, ['ownerRecord' => $this->kemi, 'pageClass' => ViewStaff::class])
            ->assertCanSeeTableRecords([$alsoKemi, $byKemi], inOrder: true)
            ->assertCanNotSeeTableRecords([$aboutKemi, $byTunde]);
        Livewire::test(HistoryRelationManager::class, ['ownerRecord' => $this->kemi, 'pageClass' => ViewStaff::class])
            ->assertCanSeeTableRecords([$aboutKemi])
            ->assertCanNotSeeTableRecords([$byKemi, $alsoKemi]);

        $views = auditViews();
        expect($views)->toHaveCount(1)
            ->and($views[0]->getProperty('view'))->toBe('staff_actions')
            ->and($views[0]->getProperty('staff_id'))->toBe($this->kemi->id);

        // The audit log's staff-member filter shows the same entries.
        Livewire::test(ListAuditLog::class)
            ->filterTable('staff', $this->kemi->id)
            ->assertCanSeeTableRecords([$alsoKemi, $byKemi])
            ->assertCanNotSeeTableRecords([$aboutKemi, $byTunde]);

        $this->get($this->console.'/staff/'.$this->kemi->id)->assertOk()->assertSee('Actions');
    });

    it('hides Actions from staff who may see the profile but not the audit log', function () {
        [$role] = app(StaffRoles::class)->createRole($this->ada, 'Staff viewers', null, ['staff.view']);
        $viewer = staffNamed('Vera');
        app(StaffRoles::class)->grant($this->ada, $viewer, $role);
        $viewer = $viewer->fresh();

        $this->actingAs($viewer, 'staff')->get($this->console.'/staff/'.$this->kemi->id)->assertOk();
        expect(ActionsRelationManager::canViewForRecord($this->kemi, ViewStaff::class))->toBeFalse()
            ->and(HistoryRelationManager::canViewForRecord($this->kemi, ViewStaff::class))->toBeTrue();

        Livewire::test(ActionsRelationManager::class, ['ownerRecord' => $this->kemi, 'pageClass' => ViewStaff::class])->assertForbidden();
        expect(auditViews())->toBe([]);
    });
});
