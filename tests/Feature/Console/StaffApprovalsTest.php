<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Platform\Staff\StaffChangeRefused;
use App\Platform\Staff\StaffLifecycle;
use App\Platform\Staff\StaffRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * P01-07 acceptance: "A two-person action cannot be approved by its
 * requester" (PLATFORM_CONSOLE.md § 1, two-person rules), wired for real to
 * grants of super-admin, finance-admin and compliance-officer.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->ada = staffNamed('Ada', ['super-admin']);
    $this->bola = staffNamed('Bola', ['super-admin']);
    $this->chidi = staffNamed('Chidi');
    $this->roles = app(StaffRoles::class);
    $this->approvals = app(ApprovalService::class);
});

function staffRole(string $name): StaffRole
{
    return StaffRole::query()->where('name', $name)->firstOrFail();
}

function holds(PlatformStaff $staff, string $role): bool
{
    return $staff->fresh()?->hasRole($role) ?? false;
}

it('cannot be approved by its requester, by the service, the policy or the console', function (string $role) {
    $request = $this->roles->grant($this->ada, $this->chidi, staffRole($role), 'Joins the team');

    expect($request)->toBeInstanceOf(ApprovalRequest::class)
        ->and($request->action)->toBe(ApprovalAction::RoleGrant)
        ->and($request->status)->toBe(ApprovalStatus::Pending)
        ->and(holds($this->chidi, $role))->toBeFalse();

    // The service refuses the requester…
    expect(fn () => $this->approvals->approve($request, $this->ada))
        ->toThrow(ApprovalRefused::class, 'You cannot decide your own request')
        ->and(fn () => $this->approvals->reject($request, $this->ada))
        ->toThrow(ApprovalRefused::class, 'You cannot decide your own request');

    // …so does the policy, and the console does not offer the buttons.
    expect(Gate::forUser($this->ada)->allows('approve', $request))->toBeFalse()
        ->and(Gate::forUser($this->ada)->allows('reject', $request))->toBeFalse();

    $this->actingAs($this->ada, 'staff');
    Livewire::test(ListApprovalRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertTableActionHidden('approve', $request)
        ->assertTableActionHidden('reject', $request);

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
        ->and(holds($this->chidi, $role))->toBeFalse();

    // A second super-admin approves; only then does the grant take effect.
    $this->actingAs($this->bola, 'staff');
    Livewire::test(ListApprovalRequests::class)
        ->assertTableActionVisible('approve', $request)
        ->callTableAction('approve', $request, data: ['note' => 'Checked with HR'])
        ->assertNotified('Approved.');

    $request->refresh();
    expect($request->status)->toBe(ApprovalStatus::Approved)
        ->and($request->approved_by_staff_id)->toBe($this->bola->id)
        ->and($request->decided_at)->not->toBeNull()
        ->and(holds($this->chidi, $role))->toBeTrue();

    $granted = AuditEntry::query()->where('event', 'staff.role_granted')->where('subject_id', $this->chidi->id)->sole();
    expect($granted->causer_id)->toBe($this->bola->id)
        ->and($granted->properties['approval_request_id'])->toBe($request->id)
        ->and($granted->properties['requested_by_staff_id'])->toBe($this->ada->id)
        ->and(AuditEntry::query()->where('event', 'approval.requested')->where('causer_id', $this->ada->id)->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'approval.approved')->where('causer_id', $this->bola->id)->exists())->toBeTrue();
})->with(['super-admin', 'finance-admin', 'compliance-officer']);

it('grants other roles at once', function () {
    expect($this->roles->grant($this->ada, $this->chidi, staffRole('support-agent')))->toBeNull()
        ->and(holds($this->chidi, 'support-agent'))->toBeTrue()
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('needs the right permission to approve', function () {
    $request = $this->roles->grant($this->ada, $this->chidi, staffRole('finance-admin'));
    $finance = staffNamed('Funke', ['finance-admin']);
    $support = staffNamed('Sade', ['support-agent']);

    foreach ([$finance, $support, $this->chidi] as $staff) {
        expect(fn () => $this->approvals->approve($request, $staff))->toThrow(ApprovalRefused::class)
            ->and(Gate::forUser($staff)->allows('approve', $request))->toBeFalse();
    }

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Pending);
});

it('needs a second super-admin, not just roles.manage, to approve a role grant', function () {
    // A custom role holding roles.manage (itself approved by a second super-admin).
    [$managers, $pending] = $this->roles->createRole($this->ada, 'Role managers', null, ['staff.view', 'roles.manage']);
    $this->approvals->approve($pending, $this->bola);
    $dayo = staffNamed('Dayo');
    $grant = $this->roles->grant($this->ada, $dayo, $managers);
    $this->approvals->approve($grant, $this->bola);

    $request = $this->roles->grant($this->ada, $this->chidi, staffRole('finance-admin'));

    expect(fn () => $this->approvals->approve($request, $dayo->fresh()))->toThrow(ApprovalRefused::class, 'second super-admin');
});

it('lets a second staff member reject, and nothing is granted', function () {
    $request = $this->roles->grant($this->ada, $this->chidi, staffRole('compliance-officer'));

    $this->approvals->reject($request, $this->bola, 'Not this quarter');

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Rejected)
        ->and($request->fresh()?->rejected_by_staff_id)->toBe($this->bola->id)
        ->and(holds($this->chidi, 'compliance-officer'))->toBeFalse()
        ->and(fn () => $this->approvals->approve($request->fresh(), $this->bola))->toThrow(ApprovalRefused::class, 'already been decided');
});

it('expires requests nobody decided in time', function () {
    $request = $this->roles->grant($this->ada, $this->chidi, staffRole('finance-admin'));

    $this->travel(8)->days();

    expect(fn () => $this->approvals->approve($request, $this->bola))->toThrow(ApprovalRefused::class, 'expired')
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired)
        ->and(holds($this->chidi, 'finance-admin'))->toBeFalse();

    $other = $this->roles->grant($this->ada, $this->chidi, staffRole('finance-admin'));
    $this->travel(8)->days();

    expect($this->approvals->expireStale())->toBe(1)
        ->and($other->fresh()?->status)->toBe(ApprovalStatus::Expired)
        ->and(AuditEntry::query()->where('event', 'approval.expired')->count())->toBe(2);
});

it('refuses the same request twice while it waits', function () {
    $this->roles->grant($this->ada, $this->chidi, staffRole('finance-admin'));

    expect(fn () => $this->roles->grant($this->bola, $this->chidi, staffRole('finance-admin')))
        ->toThrow(ApprovalRefused::class, 'already waiting');
});

it('does not grant on approval if the staff member was deactivated meanwhile', function () {
    $request = $this->roles->grant($this->ada, $this->chidi, staffRole('finance-admin'));
    app(StaffLifecycle::class)->deactivate($this->ada, $this->chidi);

    expect(fn () => $this->approvals->approve($request, $this->bola))->toThrow(ApprovalRefused::class, 'deactivated')
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
        ->and(holds($this->chidi, 'finance-admin'))->toBeFalse();
});

it('serves later two-person actions the same way (no handler: marked approved)', function () {
    // Production restores have no handler yet (P06-10); refunds have theirs since P06-07b (Modules/SaasBilling/tests/Feature/RefundTest.php).
    $requester = staffNamed('Funke', ['technical-admin']);
    $approver = staffNamed('Gbenga', ['super-admin']);

    $request = $this->approvals->request(ApprovalAction::ProductionRestore, $requester, null, 'Restore last night\'s backup to production', ['backup' => 'db-2026-10-30'], 'Bad migration');

    expect(fn () => $this->approvals->approve($request, $requester))->toThrow(ApprovalRefused::class, 'your own request')
        ->and(fn () => $this->approvals->approve($request, staffNamed('Sade', ['support-agent'])))->toThrow(ApprovalRefused::class, 'permission');

    expect($this->approvals->approve($request, $approver)->status)->toBe(ApprovalStatus::Approved);
});

it('lets the lone super-admin make a second super-admin; after that, grants need approval', function () {
    $this->bola->removeRole('super-admin');
    $lone = $this->ada->fresh();

    expect($this->roles->grant($lone, $this->chidi, staffRole('super-admin')))->toBeNull()
        ->and(holds($this->chidi, 'super-admin'))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'staff.role_granted')->where('subject_id', $this->chidi->id)->sole()->properties['bootstrap'])->toBeTrue();

    expect($this->roles->grant($lone, $this->bola->fresh(), staffRole('super-admin')))->toBeInstanceOf(ApprovalRequest::class)
        ->and(holds($this->bola, 'super-admin'))->toBeFalse();
});

it('lets only staff with roles.manage grant roles', function () {
    $operations = staffNamed('Ope', ['operations-manager']);

    expect(fn () => $this->roles->grant($operations, $this->chidi, staffRole('analyst')))->toThrow(StaffChangeRefused::class)
        ->and(Gate::forUser($operations)->allows('manageRoles', $this->chidi))->toBeFalse();
});
