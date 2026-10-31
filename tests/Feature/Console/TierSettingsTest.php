<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\RequiredDocuments;
use App\Compliance\TransactionTiers;
use App\Enums\ComplianceDocumentType;
use App\Enums\TierApplicationStatus;
use App\Enums\TierVersionStatus;
use App\Enums\TransactionTier;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\TransactionTierVersion;
use App\Models\User;
use App\Onboarding\TierGuide;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Compliance\TierDraft;
use App\Platform\Compliance\TierReview;
use App\Platform\Compliance\TierSettings;
use App\Platform\Compliance\TierSettingsRefused;
use App\Platform\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Platform\Filament\Resources\TierSettings\Pages\ListTierSettings;
use App\Platform\Filament\Resources\TierSettings\Pages\ViewTierVersion;
use App\Platform\Staff\DefaultStaffRole;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * P06-08: the Tier settings screen (ONBOARDING_COMPLIANCE.md § 5.2 "Editing
 * the limits", PLATFORM_CONSOLE.md § 1A; docs/plans/P06-subtasks.md defaults
 * 71–84). A compliance-officer proposes a tier's new terms; another staff
 * member with `tiers.approve_settings` (a super-admin) approves them in
 * Staff → Approvals; they apply from their effective date. Versions are
 * kept, a scheduled one can be cancelled, and the documents a tier requires
 * change with it.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');

    $this->kemi = staffNamed('Kemi', ['compliance-officer']);
    $this->femi = staffNamed('Femi', ['compliance-officer']);
    $this->bola = staffNamed('Bola', ['super-admin']);
    $this->tiers = app(TransactionTiers::class);
    $this->settings = app(TierSettings::class);
});

function inForceVersion(TransactionTier $tier): TransactionTierVersion
{
    return app(TransactionTiers::class)->versionInForce($tier) ?? throw new RuntimeException('No version in force.');
}

/** The terms in force for the tier, with some fields changed. */
function draftOf(TransactionTier $tier, array $changes = []): TierDraft
{
    $current = TierDraft::of(app(TransactionTiers::class)->terms($tier));

    return new TierDraft(
        $tier,
        $changes['name'] ?? $current->name,
        array_key_exists('annual', $changes) ? $changes['annual'] : $current->annualLimitKobo,
        array_key_exists('single', $changes) ? $changes['single'] : $current->singlePaymentLimitKobo,
        array_key_exists('review', $changes) ? $changes['review'] : $current->reviewThresholdKobo,
        $changes['required'] ?? $current->requiredDocuments,
        $changes['optional'] ?? $current->optionalDocuments,
        array_key_exists('days', $changes) ? $changes['days'] : $current->reviewSlaDays,
    );
}

function tierRequestOf(TransactionTierVersion $version): ApprovalRequest
{
    return ApprovalRequest::query()->findOrFail($version->fresh()?->approval_request_id);
}

function annualLimitOn(TransactionTier $tier, string $lagos): ?string
{
    return app(TransactionTiers::class)->terms($tier, CarbonImmutable::parse($lagos, 'Africa/Lagos'))->annualLimit?->format();
}

it('starts from the launch values of § 5.2 as version 1 of each tier', function () {
    [$one, $two, $three] = $this->tiers->all();

    expect([$one->version, $two->version, $three->version])->toBe([1, 1, 1])
        ->and($one->annualLimit?->format())->toBe('₦50,000,000')
        ->and($one->singlePaymentLimit?->format())->toBe('₦5,000,000')
        ->and($one->requiredDocuments)->toBe([])
        ->and($two->annualLimit?->format())->toBe('₦500,000,000')
        ->and($two->singlePaymentLimit?->format())->toBe('₦50,000,000')
        ->and($two->requiredDocuments)->toContain(ComplianceDocumentType::ScumlCertificate)
        ->and($two->optionalDocuments)->toBe([ComplianceDocumentType::StateRegulatorRegistration, ComplianceDocumentType::ProfessionalMembership])
        ->and($three->annualLimit)->toBeNull()
        ->and($three->singlePaymentLimit)->toBeNull()
        ->and($three->reviewThreshold?->format())->toBe('₦100,000,000')
        ->and($three->requiredDocuments)->toContain(ComplianceDocumentType::AmlPolicy);
});

it('acceptance: a limit change needs a second approver and applies from its effective date', function () {
    $this->actingAs($this->kemi, 'staff');

    // Kemi (compliance-officer) proposes Tier 1 at ₦80m a year and ₦8m a payment from 1 December.
    Livewire::test(ListTierSettings::class)
        ->assertTableActionVisible('propose', inForceVersion(TransactionTier::One))
        ->callTableAction('propose', inForceVersion(TransactionTier::One), data: [
            'name' => 'Standard',
            'annual' => 80_000_000,
            'single' => 8_000_000,
            'review' => null,
            'effective_from' => '2026-12-01',
            'reason' => 'Mid-market rents rose in 2026',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Version 2 of Tier 1 is waiting for a second staff member\'s approval.');

    $version = TransactionTierVersion::query()->where('tier', 1)->where('version', 2)->sole();
    $request = tierRequestOf($version);

    expect($version->status)->toBe(TierVersionStatus::Pending)
        ->and($version->annual_volume_limit_kobo)->toBe(Money::naira(80_000_000)->kobo)
        ->and($version->effective_from->toDateString())->toBe('2026-12-01')
        ->and($version->proposed_by_staff_id)->toBe($this->kemi->id)
        ->and($request->action)->toBe(ApprovalAction::TierSettings)
        ->and($request->summary)->toContain('Tier 1 v2 from 1 Dec 2026')
        ->and($request->summary)->toContain('Yearly limit: ₦50,000,000 → ₦80,000,000')
        ->and($request->reason)->toBe('Mid-market rents rose in 2026');

    // Waiting for approval, it applies on no day, not even its own.
    expect(annualLimitOn(TransactionTier::One, '2026-12-01 00:00'))->toBe('₦50,000,000')
        ->and(annualLimitOn(TransactionTier::One, '2027-06-01 12:00'))->toBe('₦50,000,000');

    // Not the proposer, and not another compliance-officer without `tiers.approve_settings`.
    expect(fn () => app(ApprovalService::class)->approve($request, $this->kemi))->toThrow(ApprovalRefused::class, 'You cannot decide your own request')
        ->and(fn () => app(ApprovalService::class)->approve($request, $this->femi))->toThrow(ApprovalRefused::class, 'You do not have the permission')
        ->and(Gate::forUser($this->kemi)->allows('approve', $request))->toBeFalse()
        ->and(Gate::forUser($this->femi)->allows('approve', $request))->toBeFalse();

    // A compliance-officer cannot open Staff → Approvals at all.
    $this->get($this->console.'/approvals')->assertForbidden();

    expect($version->fresh()?->status)->toBe(TierVersionStatus::Pending);

    // Bola (super-admin) approves in Staff → Approvals.
    $this->actingAs($this->bola, 'staff');
    Livewire::test(ListApprovalRequests::class)
        ->assertTableActionVisible('approve', $request)
        ->callTableAction('approve', $request, data: ['note' => 'Agreed'])
        ->assertNotified('Approved.');

    $version->refresh();
    expect($version->status)->toBe(TierVersionStatus::Approved)
        ->and($version->approved_by_staff_id)->toBe($this->bola->id)
        ->and($version->effective_from->toDateString())->toBe('2026-12-01');

    // From its effective date, not before.
    expect(annualLimitOn(TransactionTier::One, '2026-11-10 10:00'))->toBe('₦50,000,000')
        ->and(annualLimitOn(TransactionTier::One, '2026-11-30 23:59'))->toBe('₦50,000,000')
        ->and(annualLimitOn(TransactionTier::One, '2026-12-01 00:00'))->toBe('₦80,000,000')
        ->and(annualLimitOn(TransactionTier::Two, '2026-12-01 00:00'))->toBe('₦500,000,000');

    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:59', 'Africa/Lagos'));
    expect(app(TierGuide::class)->option(TransactionTier::One)->annualLimit?->format())->toBe('₦50,000,000');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00', 'Africa/Lagos'));
    $guide = app(TierGuide::class)->option(TransactionTier::One);
    expect($guide->annualLimit?->format())->toBe('₦80,000,000')
        ->and($guide->singlePaymentLimit?->format())->toBe('₦8,000,000');

    $acme = Organization::factory()->create(['name' => 'Acme Homes']);
    expect(app(TransactionTiers::class)->forOrganization($acme)->annualLimit()?->format())->toBe('₦80,000,000');
    $this->flushSession(); // weeks later: a fresh console session (the idle timeout is 30 minutes)
    $this->actingAs($this->bola, 'staff')->get($this->console.'/customers/'.$acme->id)->assertOk()->assertSee('₦80,000,000');

    // Each step is audited with who and why.
    $proposed = AuditEntry::query()->where('event', 'tier_settings.proposed')->sole();
    $approved = AuditEntry::query()->where('event', 'tier_settings.approved')->sole();
    expect($proposed->causer_id)->toBe($this->kemi->id)
        ->and($proposed->log_name)->toBe('compliance')
        ->and($proposed->reason)->toBe('Mid-market rents rose in 2026')
        ->and($proposed->properties['old']['annual_volume_limit_kobo'])->toBe(5_000_000_000)
        ->and($proposed->properties['attributes']['annual_volume_limit_kobo'])->toBe(8_000_000_000)
        ->and($approved->causer_id)->toBe($this->bola->id)
        ->and($approved->properties['effective_from'])->toBe('2026-12-01')
        ->and(AuditEntry::query()->where('event', 'approval.approved')->exists())->toBeTrue();
});

it('never applies a rejected or expired version, and frees the tier for another proposal', function () {
    $first = $this->settings->propose($this->kemi, draftOf(TransactionTier::Two, ['annual' => Money::naira(600_000_000)->kobo]), '2026-11-20', 'Raise Tier 2');

    expect(fn () => $this->settings->propose($this->kemi, draftOf(TransactionTier::Two, ['annual' => Money::naira(700_000_000)->kobo]), '2026-11-20', 'Again'))
        ->toThrow(TierSettingsRefused::class, 'Version 2 of Tier 2 is still waiting for approval');

    app(ApprovalService::class)->reject(tierRequestOf($first), $this->bola, 'Not this year');
    expect($first->fresh()?->status)->toBe(TierVersionStatus::Rejected)
        ->and(annualLimitOn(TransactionTier::Two, '2027-01-01 12:00'))->toBe('₦500,000,000');

    $second = $this->settings->propose($this->kemi, draftOf(TransactionTier::Two, ['annual' => Money::naira(700_000_000)->kobo]), '2026-11-20', 'Try again');
    expect($second->version)->toBe(3);

    $this->travel(8)->days();
    expect(app(ApprovalService::class)->expireStale())->toBe(1)
        ->and($second->fresh()?->status)->toBe(TierVersionStatus::Expired)
        ->and(annualLimitOn(TransactionTier::Two, '2027-01-01 12:00'))->toBe('₦500,000,000')
        ->and(AuditEntry::query()->where('event', 'tier_settings.not_approved')->count())->toBe(2);

    // The expired request no longer blocks a new one.
    expect($this->settings->propose($this->kemi, draftOf(TransactionTier::Two, ['name' => 'Business plus']), '2026-11-20', 'Rename')->version)->toBe(4);
});

it('applies a version approved after its date from the day of the approval, never backdated', function () {
    $version = $this->settings->propose($this->kemi, draftOf(TransactionTier::One, ['single' => Money::naira(6_000_000)->kobo]), '2026-11-10', 'From today');

    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00', 'Africa/Lagos'));
    app(ApprovalService::class)->approve(tierRequestOf($version), $this->bola);

    expect($version->fresh()?->effective_from->toDateString())->toBe('2026-11-13')
        ->and($this->tiers->terms(TransactionTier::One, CarbonImmutable::parse('2026-11-12 23:00', 'Africa/Lagos'))->singlePaymentLimit?->format())->toBe('₦5,000,000')
        ->and($this->tiers->terms(TransactionTier::One)->singlePaymentLimit?->format())->toBe('₦6,000,000')
        ->and(AuditEntry::query()->where('event', 'tier_settings.approved')->sole()->properties['proposed_effective_from'])->toBe('2026-11-10');
});

it('refuses proposals that break the rules, or from staff without tiers.configure', function (array $changes, string $date, string $message) {
    expect(fn () => $this->settings->propose($this->kemi, draftOf($changes['tier'] ?? TransactionTier::Two, $changes), $date, 'A reason'))
        ->toThrow(TierSettingsRefused::class, $message);
})->with([
    'nothing changes' => [[], '2026-12-01', 'Nothing changes'],
    'single above yearly' => [['single' => Money::naira(600_000_000)->kobo], '2026-12-01', 'single-payment limit cannot be above the yearly limit'],
    'review above single' => [['review' => Money::naira(60_000_000)->kobo], '2026-12-01', 'review amount cannot be above'],
    'required and optional' => [['optional' => [ComplianceDocumentType::OwnerId]], '2026-12-01', 'either required or optional'],
    'no required document' => [['required' => []], '2026-12-01', 'must require at least one document'],
    'documents for Tier 1' => [['tier' => TransactionTier::One, 'required' => [ComplianceDocumentType::OwnerId]], '2026-12-01', 'Tier 1 needs no application'],
    'no review target' => [['days' => null], '2026-12-01', 'review target'],
    'in the past' => [['name' => 'Biz'], '2026-11-09', 'from today at the earliest'],
    'over a year ahead' => [['name' => 'Biz'], '2027-11-11', 'at most a year ahead'],
    'not a date' => [['name' => 'Biz'], '1 December', 'Give the date'],
]);

it('refuses a proposal without a reason or from roles without tiers.configure', function () {
    expect(fn () => $this->settings->propose($this->kemi, draftOf(TransactionTier::Two, ['name' => 'Biz']), '2026-12-01', ' '))
        ->toThrow(TierSettingsRefused::class, 'Give a reason');

    foreach (['operations-manager', 'finance-admin', 'support-agent'] as $role) {
        $staff = staffNamed('Role'.str_replace('-', '', $role), [$role]);
        expect(fn () => $this->settings->propose($staff, draftOf(TransactionTier::Two, ['name' => 'Biz']), '2026-12-01', 'A reason'))
            ->toThrow(TierSettingsRefused::class, 'You do not have permission');
    }

    expect(TransactionTierVersion::query()->count())->toBe(3);
});

it('warns when a change lowers a limit or puts the tiers out of order, and says so to the approver', function () {
    $draft = draftOf(TransactionTier::Two, ['annual' => Money::naira(40_000_000)->kobo, 'single' => Money::naira(4_000_000)->kobo]);
    $warnings = $this->settings->warnings($draft);

    expect($warnings[0])->toContain('lowers the yearly limit and the single-payment limit')
        ->and($warnings[0])->toContain('60 days')
        ->and($warnings[1])->toContain('out of order with Tier 1');

    $version = $this->settings->propose($this->kemi, $draft, '2026-12-01', 'Tighten Tier 2');
    expect(tierRequestOf($version)->summary)->toContain('(lowers the yearly limit and single-payment limit)');
});

it('cancels a scheduled version before its date; the terms in force stay', function () {
    $version = $this->settings->propose($this->kemi, draftOf(TransactionTier::Three, ['review' => Money::naira(200_000_000)->kobo]), '2027-01-01', 'Fewer reviews');
    app(ApprovalService::class)->approve(tierRequestOf($version), $this->bola);

    $this->actingAs($this->femi, 'staff');
    Livewire::test(ListTierSettings::class)
        ->assertTableActionVisible('cancel', $version)
        ->assertTableActionHidden('cancel', inForceVersion(TransactionTier::Three))
        ->callTableAction('cancel', $version, data: ['reason' => 'Board said no'])
        ->assertNotified('Version 2 of Tier 3 is cancelled.');

    expect($version->fresh()?->status)->toBe(TierVersionStatus::Cancelled)
        ->and($this->tiers->terms(TransactionTier::Three, CarbonImmutable::parse('2027-02-01', 'Africa/Lagos'))->reviewThreshold?->format())->toBe('₦100,000,000')
        ->and(AuditEntry::query()->where('event', 'tier_settings.cancelled')->sole()->causer_id)->toBe($this->femi->id)
        ->and(fn () => $this->settings->cancel($this->femi, inForceVersion(TransactionTier::Three), 'Not needed'))->toThrow(TierSettingsRefused::class, 'Only an approved version whose date has not come');

});

it('changes the documents a tier requires from the date, without blocking an application compliance is reviewing', function () {
    $acme = Organization::factory()->create(['name' => 'Acme Homes']);
    $owner = joinAs(User::factory()->create(), $acme);
    $beta = Organization::factory()->create(['name' => 'Beta Lets']);
    $betaOwner = joinAs(User::factory()->create(), $beta);

    // Acme submits on 20 November, under the launch documents.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Africa/Lagos'));
    $submitted = submittedApplication($acme, $owner);

    // Tier 2 requires the AML policy statement from 1 December.
    $version = $this->settings->propose($this->kemi, draftOf(TransactionTier::Two, [
        'required' => [...$this->tiers->terms(TransactionTier::Two)->requiredDocuments, ComplianceDocumentType::AmlPolicy],
    ]), '2026-12-01', 'AML policy for registered agencies');
    app(ApprovalService::class)->approve(tierRequestOf($version), $this->bola);

    $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00', 'Africa/Lagos'));

    // A new application must carry it before it can be submitted.
    $draft = tierApplication($beta, $betaOwner, TransactionTier::Two, [ComplianceDocumentType::AmlPolicy]);
    expect(inOrganization($beta, fn () => app(RequiredDocuments::class)->problems($draft)))->toBe(['Upload the AML policy statement.'])
        ->and(app(RequiredDocuments::class)->requiredFor($draft))->toContain(ComplianceDocumentType::AmlPolicy);

    // Acme's, submitted before, is checked against the documents of its day and can be approved.
    expect(inOrganization($acme, fn () => app(RequiredDocuments::class)->problems($submitted)))->toBe([]);
    app(TierReview::class)->approve($this->kemi, reviewOf($submitted), 'All documents checked');
    expect($submitted->fresh()?->status)->toBe(TierApplicationStatus::Approved)
        ->and($acme->fresh()?->transaction_tier)->toBe(TransactionTier::Two);
});

it('opens the Tier settings pages for those who propose, approve or override tier limits only', function () {
    $version = inForceVersion(TransactionTier::Two);

    foreach (DefaultStaffRole::values() as $role) {
        $staff = staffNamed('Viewer'.str_replace('-', '', $role), [$role]);
        $expected = in_array($role, ['super-admin', 'compliance-officer'], true) ? 200 : 403;

        foreach (['/tier-settings', '/tier-settings/'.$version->id] as $page) {
            $this->flushSession();
            $this->actingAs($staff, 'staff')->get($this->console.$page)->assertStatus($expected);
        }
    }

    $this->flushSession();
    $this->actingAs($this->kemi, 'staff')->get($this->console.'/tier-settings')
        ->assertOk()->assertSee('Tier settings')->assertSee('In force')->assertSee('₦500,000,000');
});

it('shows a waiting version beside the terms in force, so the approver sees what changes', function () {
    $version = $this->settings->propose($this->kemi, draftOf(TransactionTier::Two, [
        'single' => Money::naira(80_000_000)->kobo,
        'annual' => Money::naira(800_000_000)->kobo,
        'optional' => [ComplianceDocumentType::ProfessionalMembership],
    ]), '2026-12-01', 'Commercial lettings');

    $this->actingAs($this->bola, 'staff');
    Livewire::test(ViewTierVersion::class, ['record' => $version->id])
        ->assertSee('Waiting for approval')
        ->assertSee('Single-payment limit: ₦50,000,000 → ₦80,000,000')
        ->assertSee('Optional documents removed: State real-estate regulator registration')
        ->assertSee('Commercial lettings');

    // Only `tiers.configure` proposes: the approver may not propose a second version meanwhile, nobody may.
    expect(Gate::forUser($this->bola)->allows('propose', TransactionTierVersion::class))->toBeTrue()
        ->and(Gate::forUser(staffNamed('Ops', ['operations-manager']))->allows('propose', TransactionTierVersion::class))->toBeFalse()
        ->and(tierRequestOf($version)->status)->toBe(ApprovalStatus::Pending);
});
