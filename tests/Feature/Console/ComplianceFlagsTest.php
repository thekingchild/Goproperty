<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\Monitoring\ComplianceFlags;
use App\Compliance\Monitoring\TierMonitor;
use App\Compliance\Monitoring\TierMonitoringTemplates;
use App\Compliance\Monitoring\TierRestrictions;
use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\OrganizationStatus;
use App\Enums\RestrictionSource;
use App\Enums\TransactionTier;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Models\OrganizationVolumeSnapshot;
use App\Models\User;
use App\Notifications\TierMonitoringNotice;
use App\Platform\Compliance\FlagQueue;
use App\Platform\Compliance\ReviewFlag;
use App\Platform\Compliance\TierSettingsRefused;
use App\Platform\Filament\Resources\ComplianceFlags\Pages\ListComplianceFlags;
use App\Platform\Filament\Resources\ComplianceFlags\Pages\ViewComplianceFlag;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * P06-09: the console's side of the Transaction Tier monitoring
 * (ONBOARDING_COMPLIANCE.md § 5.4–5.5, PLATFORM_CONSOLE.md § 1A; defaults
 * 95, 97 and 99 of docs/plans/P06-subtasks.md): Compliance → Flags (the
 * queue, `compliance.flags`) with Acknowledge, Assign to me, Resolve,
 * Dismiss and Extend grace (`compliance.restrict`), through
 * App\Platform\Compliance\FlagQueue; Restrict / Lift restriction on the
 * organization's page (`compliance.restrict`) through
 * App\Compliance\Monitoring\TierRestrictions.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');

    $this->kemi = staffNamed('Kemi', ['compliance-officer']);
    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Lets', 'slug' => 'beta-lets']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ade Owner', 'email' => 'ade@acme.test']), $this->acme);
});

/** Raises a flag in the organization as the monitoring would. */
function raiseFlag(Organization $organization, ComplianceFlagType $type, array $attributes = []): ReviewFlag
{
    $flag = app(OrganizationContext::class)->run($organization, fn (): ?ComplianceFlag => app(ComplianceFlags::class)->raise($type, $type->value.':'.Str::random(10), $attributes + ['tier' => $organization->transaction_tier]));

    return ReviewFlag::query()->findOrFail($flag?->id);
}

function flagNow(ReviewFlag $flag): ReviewFlag
{
    return ReviewFlag::query()->findOrFail($flag->id);
}

it('opens on the flags for compliance that are still open, oldest first, across organizations', function () {
    $structuring = raiseFlag($this->acme, ComplianceFlagType::PossibleStructuring, ['metric_value_kobo' => Money::naira(13_500_000)->kobo]);
    $this->travel(1)->minutes();
    $spike = raiseFlag($this->beta, ComplianceFlagType::Spike);
    $near = raiseFlag($this->acme, ComplianceFlagType::Volume80);
    $expiring = raiseFlag($this->acme, ComplianceFlagType::DocumentExpiring);
    $closed = raiseFlag($this->beta, ComplianceFlagType::CashThreshold);
    app(FlagQueue::class)->dismiss($this->kemi, $closed, 'Reported by the agency already');

    $this->actingAs($this->kemi, 'staff');
    Livewire::test(ListComplianceFlags::class)
        ->assertCanSeeTableRecords([$structuring, $spike], inOrder: true)
        ->assertCanNotSeeTableRecords([$near, $expiring, $closed])
        ->filterTable('queue', 'all')
        ->assertCanSeeTableRecords([$structuring, $spike, $near, $expiring])
        ->filterTable('status', [ComplianceFlagStatus::Dismissed->value])
        ->assertCanSeeTableRecords([$closed])
        ->assertCanNotSeeTableRecords([$structuring]);

    $this->get($this->console.'/compliance-flags')->assertOk()->assertSee('Possible structuring')->assertSee('₦13,500,000');
    $this->get($this->console.'/compliance-flags/'.$structuring->id)->assertOk()->assertSee('Acme Homes')->assertSee('Not told (compliance only)');
});

it('acknowledges, assigns, resolves and dismisses with a note, each audited', function () {
    $flag = raiseFlag($this->acme, ComplianceFlagType::CashThreshold, ['metric_value_kobo' => Money::naira(6_000_000)->kobo]);
    $bola = staffNamed('Bola', ['super-admin']);
    $this->actingAs($this->kemi, 'staff');

    Livewire::test(ViewComplianceFlag::class, ['record' => $flag->id])
        ->assertActionVisible('acknowledge')
        ->assertActionHidden('extendGrace')
        ->callAction('acknowledge')
        ->assertNotified('Acknowledged.');
    expect(flagNow($flag)->status)->toBe(ComplianceFlagStatus::Acknowledged)
        ->and(flagNow($flag)->assigned_staff_id)->toBe($this->kemi->id);

    app(FlagQueue::class)->assignToMe($bola, $flag);
    expect(flagNow($flag)->assigned_staff_id)->toBe($bola->id);

    Livewire::test(ViewComplianceFlag::class, ['record' => $flag->id])
        ->callAction('resolve', data: ['note' => 'Agency filed the CTR on 11 Nov'])
        ->assertHasNoActionErrors()
        ->assertNotified('Resolved.');

    $resolved = flagNow($flag);
    expect($resolved->status)->toBe(ComplianceFlagStatus::Resolved)
        ->and($resolved->resolution_note)->toBe('Agency filed the CTR on 11 Nov')
        ->and($resolved->resolved_by_staff_id)->toBe($this->kemi->id)
        ->and(AuditEntry::query()->where('event', 'compliance_flag.resolved')->sole()->reason)->toBe('Agency filed the CTR on 11 Nov')
        ->and(AuditEntry::query()->where('event', 'compliance_flag.acknowledged')->sole()->causer_id)->toBe($this->kemi->id)
        ->and(fn () => app(FlagQueue::class)->dismiss($this->kemi, $flag, 'Again'))->toThrow(TierSettingsRefused::class, 'cannot change');

    // A note is required.
    $other = raiseFlag($this->acme, ComplianceFlagType::Spike);
    expect(fn () => app(FlagQueue::class)->dismiss($this->kemi, $other, ' '))->toThrow(TierSettingsRefused::class, 'Give a note');
});

it('leaves the volume flags to the monitoring: staff cannot resolve or dismiss them', function () {
    $flag = raiseFlag($this->acme, ComplianceFlagType::Volume100, ['grace_ends_at' => now()->addDays(30)]);
    $this->actingAs($this->kemi, 'staff');

    Livewire::test(ViewComplianceFlag::class, ['record' => $flag->id])
        ->assertActionHidden('resolve')
        ->assertActionHidden('dismiss')
        ->assertActionVisible('extendGrace');

    expect(fn () => app(FlagQueue::class)->resolve($this->kemi, $flag, 'Looks fine'))->toThrow(TierSettingsRefused::class, 'closes itself');
});

it('extends a yearly-limit grace with a reason, lifting the restriction its end caused', function () {
    // Its grace ended yesterday and the monitoring restricted it (set here directly: the world has no payments).
    $flag = raiseFlag($this->acme, ComplianceFlagType::Volume100, ['grace_ends_at' => now()->subDay(), 'metric_value_kobo' => Money::naira(51_000_000)->kobo, 'limit_kobo' => Money::naira(50_000_000)->kobo]);
    $this->acme->forceFill(['status' => OrganizationStatus::Restricted, 'restriction_source' => RestrictionSource::TierMonitoring, 'restricted_at' => now()])->save();

    $this->actingAs($this->kemi, 'staff');
    Livewire::test(ViewComplianceFlag::class, ['record' => $flag->id])
        ->callAction('extendGrace', data: ['until' => '2026-12-15', 'reason' => 'Owner is gathering the CAC documents'])
        ->assertHasNoActionErrors()
        ->assertNotified('The grace period now ends on 15 Dec 2026.');

    expect(flagNow($flag)->grace_ends_at?->setTimezone('Africa/Lagos')->toDateTimeString())->toBe('2026-12-15 00:00:00')
        ->and(Organization::query()->find($this->acme->id)?->status)->toBe(OrganizationStatus::Active)
        ->and(AuditEntry::query()->where('event', 'compliance_flag.grace_extended')->sole()->reason)->toBe('Owner is gathering the CAC documents')
        ->and(fn () => app(FlagQueue::class)->extendGrace($this->kemi, $flag, '2027-03-01', 'Too far'))->toThrow(TierSettingsRefused::class, 'up to 90 days')
        ->and(fn () => app(FlagQueue::class)->extendGrace($this->kemi, $flag, '2026-12-10', 'Earlier'))->toThrow(TierSettingsRefused::class, 'later than 15 Dec 2026')
        ->and(fn () => app(FlagQueue::class)->extendGrace(staffNamed('Olu', ['operations-manager']), $flag, '2026-12-20', 'Not mine'))->toThrow(TierSettingsRefused::class, 'permission');
});

it('lets compliance restrict an organization and lift it, telling the owners; the monitoring never lifts it', function () {
    $this->actingAs($this->kemi, 'staff');

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->assertActionVisible('restrict')
        ->assertActionHidden('liftRestriction')
        ->callAction('restrict', data: ['reason' => 'Unexplained transfers under review'])
        ->assertHasNoActionErrors()
        ->assertNotified('Acme Homes is restricted.');

    $acme = Organization::query()->findOrFail($this->acme->id);
    expect($acme->status)->toBe(OrganizationStatus::Restricted)
        ->and($acme->restriction_source)->toBe(RestrictionSource::Staff)
        ->and(AuditEntry::query()->where('event', 'organization.restricted')->sole()->causer_id)->toBe($this->kemi->id);
    Notification::assertSentTo($this->owner, TierMonitoringNotice::class, fn (TierMonitoringNotice $notice): bool => $notice->template === TierMonitoringTemplates::RESTRICTED
        && str_contains((string) $notice->values['restriction.reason'], "GoProperty's compliance team has restricted"));

    // The daily run leaves a staff restriction alone.
    app(TierMonitor::class)->daily();
    expect(Organization::query()->findOrFail($this->acme->id)->status)->toBe(OrganizationStatus::Restricted);

    $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()->assertSee('Restricted by GoProperty compliance');

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->assertActionHidden('restrict')
        ->callAction('liftRestriction', data: ['reason' => 'Transfers explained by the owner'])
        ->assertNotified('The restriction on Acme Homes is lifted.');
    expect(Organization::query()->findOrFail($this->acme->id)->status)->toBe(OrganizationStatus::Active);
    Notification::assertSentTo($this->owner, TierMonitoringNotice::class, fn (TierMonitoringNotice $notice): bool => $notice->template === TierMonitoringTemplates::RESTRICTION_LIFTED);

    // Only an active organization is restricted; a reason is required.
    $this->beta->forceFill(['status' => OrganizationStatus::Suspended])->save();
    expect(fn () => app(TierRestrictions::class)->restrict($this->kemi, $this->beta, 'A reason'))->toThrow(TierSettingsRefused::class, 'only an active organization')
        ->and(fn () => app(TierRestrictions::class)->restrict($this->kemi, $this->acme, ' '))->toThrow(TierSettingsRefused::class, 'Give a reason');
});

it('lets only compliance.restrict restrict, and compliance.flags work the queue', function () {
    $flag = raiseFlag($this->acme, ComplianceFlagType::Spike);

    foreach (['operations-manager', 'finance-admin', 'support-agent'] as $role) {
        $staff = staffNamed('Role'.str_replace('-', '', $role), [$role]);
        $this->flushSession();
        $this->actingAs($staff, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionHidden('restrict')
            ->assertActionHidden('liftRestriction');

        expect(fn () => app(TierRestrictions::class)->restrict($staff, $this->acme, 'A reason'))->toThrow(TierSettingsRefused::class, 'permission')
            ->and(fn () => app(FlagQueue::class)->acknowledge($staff, $flag))->toThrow(TierSettingsRefused::class, 'permission');
        $this->get($this->console.'/compliance-flags')->assertForbidden();
    }

    expect(Organization::query()->findOrFail($this->acme->id)->status)->toBe(OrganizationStatus::Active)
        ->and(flagNow($flag)->status)->toBe(ComplianceFlagStatus::Open);
});

it('shows the latest measure and the limits on the organization\'s page', function () {
    app(OrganizationContext::class)->run($this->acme, fn () => tap(new OrganizationVolumeSnapshot, fn (OrganizationVolumeSnapshot $snapshot) => $snapshot->forceFill([
        'organization_id' => $this->acme->id,
        'date' => '2026-11-09',
        'tier' => TransactionTier::One,
        'rolling_12m_volume_kobo' => Money::naira(42_000_000)->kobo,
        'forecast_12m_kobo' => Money::naira(58_000_000)->kobo,
        'largest_payment_kobo' => Money::naira(3_000_000)->kobo,
        'month_volume_kobo' => Money::naira(4_000_000)->kobo,
        'annual_limit_kobo' => Money::naira(50_000_000)->kobo,
        'single_payment_limit_kobo' => Money::naira(5_000_000)->kobo,
    ])->save()));

    $this->actingAs($this->kemi, 'staff');
    $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()
        ->assertSee('₦42,000,000 (84 % of ₦50,000,000) on 9 Nov 2026')
        ->assertSee('₦58,000,000')
        ->assertSee('₦3,000,000');
});
