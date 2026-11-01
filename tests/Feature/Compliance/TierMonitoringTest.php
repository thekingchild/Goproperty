<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\Monitoring\PaymentChecks;
use App\Compliance\Monitoring\TierMonitor;
use App\Compliance\Monitoring\TierMonitoringTemplates;
use App\Compliance\TierApplications;
use App\Compliance\TransactionTiers;
use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\RestrictionSource;
use App\Enums\TransactionTier;
use App\Models\ApprovalRequest;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Models\OrganizationVolumeSnapshot;
use App\Models\User;
use App\Notifications\TierMonitoringNotice;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Compliance\DocumentExpiryCheck;
use App\Platform\Compliance\TierDraft;
use App\Platform\Compliance\TierOverrides;
use App\Platform\Compliance\TierReview;
use App\Platform\Compliance\TierSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Leasing\Models\Lease;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Properties\Models\Property;

require_once __DIR__.'/../../../Modules/Tenants/tests/Feature/Portal/portal-world.php';
require_once __DIR__.'/../../../Modules/Payments/tests/Feature/proof-helpers.php';

/*
 * P06-09: Transaction Tier monitoring (ONBOARDING_COMPLIANCE.md § 5.1,
 * § 5.4–5.5; defaults 85–99 of docs/plans/P06-subtasks.md). Each confirmed
 * payment is checked (App\Compliance\Monitoring\PaymentChecks) and every
 * organization is measured daily (TierMonitor::daily(), the 03:30
 * `compliance-evaluate-tiers` run and `php artisan compliance:evaluate-tiers`):
 * snapshots, the volume, forecast, payment and spike flags, the 30-day
 * grace and the restriction (TierRestrictions).
 *
 * The world is the tenant portal's (portal-world.php): Adeyemi Realty
 * (acme-homes, Tier 1: ₦50m a year, ₦5m a payment) with Kemi's bill and
 * portal account, her neighbour Tunde, and Bello Homes beside it. Tunde's
 * ₦3m transfers (60 % of the single-payment limit: no structuring) make the
 * volume.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    Notification::fake();
    portalWorld();
    $this->tundeTenant = $this->neighbourLease->tenant_profile_id;
});

afterEach(fn () => billsFreshRequest());

/** Tunde pays the agency ₦3m by transfer, $times times, confirmed by the owner. */
function tundePays(int $times, string $amount = '3,000,000', array $changes = []): void
{
    for ($i = 0; $i < $times; $i++) {
        confirmedPayment(test()->acme, test()->owner, test()->tundeTenant, $amount, $changes);
    }
}

/** @return Collection<int, ComplianceFlag> the organization's flags, oldest first */
function flagsOf(Organization $organization, ?ComplianceFlagType $type = null): Collection
{
    return inOrganization($organization, fn (): Collection => ComplianceFlag::query()
        ->when($type !== null, fn ($query) => $query->where('type', $type->value))
        ->orderBy('id')
        ->get());
}

function runTierMonitoring(): array
{
    billsFreshRequest();

    return app(TierMonitor::class)->daily();
}

function freshAcme(): Organization
{
    return Organization::query()->findOrFail(test()->acme->id);
}

/** The owners' notices of one template sent to the user. */
function tierNoticesTo(User $user, string $template): int
{
    return Notification::sent($user, TierMonitoringNotice::class)->filter(fn (TierMonitoringNotice $notice): bool => $notice->template === $template)->count();
}

it('acceptance: an organization crossing 80 % and 100 % gets one flag each; 30 days later without an application it is restricted, and its tenants still see bills, send proofs and get receipts', function () {
    // 13 × ₦3m = ₦39m: under 80 % of ₦50m.
    tundePays(13);
    expect(flagsOf($this->acme))->toHaveCount(0);

    // The 14th (₦42m, 84 %) crosses 80 % when it is confirmed: one flag, the owner told in the app and by email.
    tundePays(1);
    $volume80 = flagsOf($this->acme, ComplianceFlagType::Volume80)->sole();
    expect($volume80->metric_value_kobo)->toBe(Money::naira(42_000_000)->kobo)
        ->and($volume80->limit_kobo)->toBe(Money::naira(50_000_000)->kobo)
        ->and($volume80->tier)->toBe(TransactionTier::One)
        ->and($volume80->owners_notified_at)->not->toBeNull()
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::VOLUME_80))->toBe(1);
    Notification::assertSentTo($this->owner, TierMonitoringNotice::class, fn (TierMonitoringNotice $notice, array $channels): bool => $notice->template === TierMonitoringTemplates::VOLUME_80 && $channels === ['database', 'mail']);

    // The daily run and more payments under the limit raise nothing more.
    runTierMonitoring();
    $this->travelTo(CarbonImmutable::parse('2026-12-26 10:00', 'Africa/Lagos'));
    runTierMonitoring();
    tundePays(2); // ₦48m
    expect(flagsOf($this->acme))->toHaveCount(1);

    // ₦51m: over the limit. One `volume_100` with a 30-day grace; the owner hears "upgrade required".
    tundePays(1);
    runTierMonitoring();
    runTierMonitoring();
    tundePays(1); // ₦54m
    $volume100 = flagsOf($this->acme, ComplianceFlagType::Volume100)->sole();
    expect(flagsOf($this->acme, ComplianceFlagType::Volume80))->toHaveCount(1)
        ->and(flagsOf($this->acme))->toHaveCount(2)
        ->and($volume100->metric_value_kobo)->toBe(Money::naira(51_000_000)->kobo)
        ->and($volume100->grace_ends_at?->setTimezone('Africa/Lagos')->toDateTimeString())->toBe('2027-01-25 00:00:00')
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::VOLUME_100))->toBe(1)
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::VOLUME_80))->toBe(1);

    $email = (new TierMonitoringNotice($this->acme->id, TierMonitoringTemplates::VOLUME_100, Notification::sent($this->owner, TierMonitoringNotice::class)->last()->values))->toMail($this->owner);
    expect($email->subject)->toBe('Upgrade required: Adeyemi Realty Ltd has reached its Transaction Tier limit')
        ->and((string) $email->render())->toContain('Apply for a higher tier by 25 January 2027');

    // The owner sees "upgrade required" on every workspace page.
    billsFreshRequest();
    $this->actingAs($this->owner)->get('/o/acme-homes/payments')->assertOk()
        ->assertSee('data-test="tier-upgrade-notice"', false)
        ->assertSee('Apply for a higher tier by 25 January 2027');

    // The day before the grace ends: still active.
    $this->travelTo(CarbonImmutable::parse('2027-01-24 23:59', 'Africa/Lagos'));
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Active);

    // When it ends, with no application sent: restricted by the monitoring, the owner told, audited.
    $this->travelTo(CarbonImmutable::parse('2027-01-25 00:00', 'Africa/Lagos'));
    runTierMonitoring();
    $acme = freshAcme();
    expect($acme->status)->toBe(OrganizationStatus::Restricted)
        ->and($acme->restriction_source)->toBe(RestrictionSource::TierMonitoring)
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::RESTRICTED))->toBe(1)
        ->and(flagsOf($this->acme))->toHaveCount(2)
        ->and(AuditEntry::query()->where('event', 'organization.restricted')->sole()->causer_id)->toBeNull();

    // Restricted: no new leases or buildings, and every member sees why.
    $this->acme = $acme;
    expect(inOrganization($acme, fn (): bool => Gate::forUser($this->owner)->allows('create', Lease::class)))->toBeFalse()
        ->and(inOrganization($acme, fn (): bool => Gate::forUser($this->owner)->allows('create', Property::class)))->toBeFalse();
    billsFreshRequest();
    $this->actingAs($this->owner)->get('/o/acme-homes/payments')->assertOk()
        ->assertSee('data-test="tier-restricted-notice"', false)
        ->assertSee('This organization is restricted');

    // Kemi still sees her bills…
    portalPage($this->kemi, '/bills')->assertOk()->assertSee($this->bill->number);
    portalPage($this->kemi, "/bills/{$this->bill->id}")->assertOk()->assertSee('Paid already?');

    // …sends a proof of payment…
    $proof = submitTenantProof($this->acme, $this->kemi, $this->bill, proofFile('rent.png', 7), ['amount' => '1,500,000']);
    $payment = proofPayment($this->acme, $proof);
    expect($payment->status)->toBe(PaymentStatus::PendingConfirmation);

    // …and gets her receipt when the agency confirms it.
    confirmPayment($this->acme, $this->owner, $payment);
    $receipt = receiptOf($this->acme, $payment);
    portalPage($this->kemi, "/receipts/{$receipt->id}")->assertOk()->assertSee($receipt->number);

    // Still one flag each.
    expect(flagsOf($this->acme, ComplianceFlagType::Volume80))->toHaveCount(1)
        ->and(flagsOf($this->acme, ComplianceFlagType::Volume100))->toHaveCount(1);
});

it('never restricts an organization whose upgrade application is with compliance, and lifts a restriction once one is sent', function () {
    tundePays(17); // ₦51m
    $this->travelTo(now()->addDays(30));
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Restricted);

    // Sending an application lifts it at once: the owner is told.
    $application = tierApplication($this->acme, $this->owner);
    inOrganization($this->acme, fn () => app(TierApplications::class)->submit($this->owner, $application));
    expect(freshAcme()->status)->toBe(OrganizationStatus::Active)
        ->and(freshAcme()->restriction_source)->toBeNull()
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::RESTRICTION_LIFTED))->toBe(1);

    // While it is reviewed, the daily run leaves it alone.
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Active);

    billsFreshRequest();
    $this->actingAs($this->owner)->get('/o/acme-homes/payments')->assertOk()
        ->assertSee('Your upgrade application is with compliance');

    // Rejected: the ended grace restricts it again at once.
    seedStaffRoles();
    app(TierReview::class)->reject(staffNamed('Kemi', ['compliance-officer']), reviewOf($application), 'CAC certificate is for another business');
    expect(freshAcme()->status)->toBe(OrganizationStatus::Restricted);
});

it('closes the old tier\'s flags and lifts the restriction when a higher tier is approved', function () {
    tundePays(17);
    $this->travelTo(now()->addDays(30));
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Restricted);

    $application = submittedApplication($this->acme, $this->owner);
    seedStaffRoles();
    app(TierReview::class)->approve(staffNamed('Kemi', ['compliance-officer']), reviewOf($application), 'Documents in order');

    $acme = freshAcme();
    expect($acme->transaction_tier)->toBe(TransactionTier::Two)
        ->and($acme->status)->toBe(OrganizationStatus::Active)
        ->and(flagsOf($this->acme)->every(fn (ComplianceFlag $flag): bool => $flag->status === ComplianceFlagStatus::Resolved))->toBeTrue()
        ->and(flagsOf($this->acme, ComplianceFlagType::Volume100)->sole()->resolution_note)->toBe('The organization moved to Tier 2.');
});

it('resolves a volume flag once the collections fall back, and a later crossing opens a new one', function () {
    tundePays(14); // ₦42m on 25 Dec 2026
    expect(flagsOf($this->acme, ComplianceFlagType::Volume80))->toHaveCount(1);

    // A year and a day later the payments are out of the rolling 12 months.
    $this->travelTo(CarbonImmutable::parse('2027-12-26 10:00', 'Africa/Lagos'));
    runTierMonitoring();
    $first = flagsOf($this->acme, ComplianceFlagType::Volume80)->sole();
    expect($first->status)->toBe(ComplianceFlagStatus::Resolved)
        ->and($first->resolution_note)->toBe('The yearly collections fell back under 80 % of the limit.')
        ->and($first->resolved_by_staff_id)->toBeNull();

    tundePays(14);
    expect(flagsOf($this->acme, ComplianceFlagType::Volume80))->toHaveCount(2)
        ->and(flagsOf($this->acme, ComplianceFlagType::Volume80)->last()->dedupe_key)->toBe("volume_80:{$this->acme->id}:t1:2");
});

it('lifts a restriction the monitoring made when the collections fall back under the limit', function () {
    tundePays(17);
    $this->travelTo(now()->addDays(30));
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Restricted);

    $this->travelTo(CarbonImmutable::parse('2027-12-26 10:00', 'Africa/Lagos'));
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Active)
        ->and(AuditEntry::query()->where('event', 'organization.restriction_lifted')->sole()->reason)->toBe('Its yearly collections are within its Transaction Tier limit again.');
});

it('counts confirmed payments only, from every channel, and keeps a daily snapshot', function () {
    tundePays(2); // ₦6m confirmed
    recordPayment($this->acme, $this->owner, $this->tundeTenant, '9,000,000'); // waiting: not counted
    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '1,000,000', ['method' => 'cash', 'note' => 'Paid at the office']);
    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '500,000', ['method' => 'pos', 'note' => 'POS at the office']);
    confirmedPayment($this->other, $this->otherOwner, $this->foreignLease->tenant_profile_id, '4,000,000'); // another organization

    runTierMonitoring();
    runTierMonitoring(); // the same day: the row is replaced

    $snapshot = inOrganization($this->acme, fn (): OrganizationVolumeSnapshot => OrganizationVolumeSnapshot::query()->sole());
    expect($snapshot->rolling_12m_volume_kobo)->toBe(Money::naira(7_500_000)->kobo)
        ->and($snapshot->largest_payment_kobo)->toBe(Money::naira(3_000_000)->kobo)
        ->and($snapshot->month_volume_kobo)->toBe(Money::naira(7_500_000)->kobo)
        ->and($snapshot->annual_limit_kobo)->toBe(Money::naira(50_000_000)->kobo)
        ->and($snapshot->single_payment_limit_kobo)->toBe(Money::naira(5_000_000)->kobo)
        ->and($snapshot->tier)->toBe(TransactionTier::One)
        ->and($snapshot->date->toDateString())->toBe('2026-12-25');

    // The command runs the same check by hand.
    $this->travelTo(now()->addDay());
    expect(Artisan::call('compliance:evaluate-tiers'))->toBe(0)
        ->and(Artisan::output())->toContain('Measured');
    expect(inOrganization($this->acme, fn (): int => OrganizationVolumeSnapshot::query()->count()))->toBe(2);
});

it('raises single_over and cash_threshold on the payment, telling the owner, and possible_structuring for compliance only', function () {
    // ₦6m by transfer: over Tier 1's ₦5m single-payment limit.
    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '6,000,000');
    $single = flagsOf($this->acme, ComplianceFlagType::SingleOver)->sole();
    expect($single->limit_kobo)->toBe(Money::naira(5_000_000)->kobo)
        ->and($single->subject_type)->toBe('payment')
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::SINGLE_OVER))->toBe(1);

    // ₦5m in cash from a person: the MLPPA threshold (and not over the ₦5m single limit).
    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '5,000,000', ['method' => 'cash', 'note' => 'Paid at the office']);
    $cash = flagsOf($this->acme, ComplianceFlagType::CashThreshold)->sole();
    expect($cash->limit_kobo)->toBe(Money::naira(5_000_000)->kobo)
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::CASH_THRESHOLD))->toBe(1);

    // ₦4.9m in cash from a company is under its ₦10m threshold.
    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '4,900,000', ['method' => 'cash', 'payerType' => 'corporate', 'note' => 'Paid at the office']);
    expect(flagsOf($this->acme, ComplianceFlagType::CashThreshold))->toHaveCount(1);

    // Kemi pays ₦4.5m in cash three times in ten days: just under the threshold each time.
    $kemiTenant = $this->lease->tenant_profile_id;
    foreach (['2026-12-25', '2026-12-29', '2027-01-03'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 10:00", 'Africa/Lagos'));
        confirmedPayment($this->acme, $this->owner, $kemiTenant, '4,500,000', ['method' => 'cash', 'note' => 'Paid at the office']);
    }

    $structuring = flagsOf($this->acme, ComplianceFlagType::PossibleStructuring)->sole();
    expect($structuring->tenant_profile_id)->toBe($kemiTenant)
        ->and($structuring->metric_value_kobo)->toBe(Money::naira(13_500_000)->kobo)
        ->and($structuring->details['payment_ids'])->toHaveCount(3)
        ->and($structuring->owners_notified_at)->toBeNull();

    // A fourth within the window does not raise another.
    confirmedPayment($this->acme, $this->owner, $kemiTenant, '4,600,000', ['method' => 'cash', 'note' => 'Paid at the office']);
    expect(flagsOf($this->acme, ComplianceFlagType::PossibleStructuring))->toHaveCount(1);

    // Each payment is checked once: running the check again raises nothing.
    $lastPayment = paymentsOf($this->acme)->last();
    app(PaymentChecks::class)->check($this->acme, $lastPayment->id);
    app(PaymentChecks::class)->check($this->acme, $single->subject_id);
    expect(flagsOf($this->acme, ComplianceFlagType::SingleOver))->toHaveCount(1);
});

it('flags Tier 3 payments above the review amount for compliance only', function () {
    $this->acme->forceFill(['transaction_tier' => TransactionTier::Three])->save();

    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '100,000,000');
    expect(flagsOf($this->acme, ComplianceFlagType::SingleOver))->toHaveCount(0);

    confirmedPayment($this->acme, $this->owner, $this->tundeTenant, '100,000,001');
    $flag = flagsOf($this->acme, ComplianceFlagType::SingleOver)->sole();
    expect($flag->details['review'])->toBeTrue()
        ->and($flag->owners_notified_at)->toBeNull()
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::SINGLE_OVER))->toBe(0)
        // No yearly cap on Tier 3: no volume flags.
        ->and(flagsOf($this->acme, ComplianceFlagType::Volume100))->toHaveCount(0);
});

it('raises forecast_over from the leases\' rent for the next 12 months', function () {
    $palm = inOrganization($this->acme, fn (): Property => Property::query()->where('slug', 'palm-court')->sole());
    $tenant = tenantIn($this->acme, ['first_name' => 'Large', 'last_name' => 'Tenant', 'phone' => '+2348036660000', 'email' => 'large.tenant@example.com']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Penthouse']), $tenant, ['billingCycle' => 'monthly', 'rent' => '4,500,000', 'deposit' => '0']);

    runTierMonitoring();

    $flag = flagsOf($this->acme, ComplianceFlagType::ForecastOver)->sole();
    expect($flag->metric_value_kobo)->toBeGreaterThan(Money::naira(50_000_000)->kobo)
        ->and(tierNoticesTo($this->owner, TierMonitoringTemplates::FORECAST_OVER))->toBe(1);

    billsFreshRequest();
    $this->actingAs($this->owner)->get('/o/acme-homes/payments')->assertOk()
        ->assertSee('data-test="tier-approaching-notice"', false)
        ->assertSee('Your leases will bill more than the ₦50,000,000 a year your tier allows.');

    // The agent sees no prompt: only the owners see the Transaction Tier.
    $agent = joinAs(User::factory()->create(['name' => 'Segun Agent']), $this->acme, OrganizationRole::Agent);
    billsFreshRequest();
    $this->actingAs($agent)->get('/o/acme-homes/payments')->assertOk()
        ->assertDontSee('data-test="tier-approaching-notice"', false);
});

it('raises a spike when the month is over three times the average of the six before', function () {
    // ₦1m a month from July to December 2026…
    foreach (['2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10', '2026-11-10', '2026-12-10'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 10:00", 'Africa/Lagos'));
        tundePays(1, '1,000,000');
    }

    // …then ₦3.5m in January 2027.
    $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00', 'Africa/Lagos'));
    tundePays(1, '3,000,000');
    runTierMonitoring();
    expect(flagsOf($this->acme, ComplianceFlagType::Spike))->toHaveCount(0);

    tundePays(1, '500,000');
    runTierMonitoring();
    runTierMonitoring();
    $spike = flagsOf($this->acme, ComplianceFlagType::Spike)->sole();
    expect($spike->details['month'])->toBe('2027-01')
        ->and($spike->limit_kobo)->toBe(Money::naira(3_000_000)->kobo)
        ->and($spike->owners_notified_at)->toBeNull();
});

it('gives 60 days when a lowered limit puts the organization over it, and says so', function () {
    tundePays(15); // ₦45m: within Tier 1's ₦50m

    // Compliance lowers Tier 1's yearly limit to ₦40m from today; a super-admin approves.
    seedStaffRoles();
    $current = TierDraft::of(app(TransactionTiers::class)->terms(TransactionTier::One));
    $draft = new TierDraft(TransactionTier::One, $current->name, Money::naira(40_000_000)->kobo, $current->singlePaymentLimitKobo, $current->reviewThresholdKobo, $current->requiredDocuments, $current->optionalDocuments, $current->reviewSlaDays);
    $version = app(TierSettings::class)->propose(staffNamed('Kemi', ['compliance-officer']), $draft, '2026-12-25', 'Align Tier 1 with the regulator');
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($version->fresh()?->approval_request_id), staffNamed('Bola', ['super-admin']));

    runTierMonitoring();

    $flag = flagsOf($this->acme, ComplianceFlagType::Volume100)->sole();
    expect($flag->limit_kobo)->toBe(Money::naira(40_000_000)->kobo)
        ->and($flag->grace_ends_at?->setTimezone('Africa/Lagos')->toDateString())->toBe('2027-02-23')
        ->and($flag->details['limit_lowered_on'])->toBe('2026-12-25');

    $values = Notification::sent($this->owner, TierMonitoringNotice::class)->first(fn (TierMonitoringNotice $notice): bool => $notice->template === TierMonitoringTemplates::VOLUME_100)?->values;
    expect($values['grace.date'] ?? null)->toBe('23 February 2027')
        ->and($values['limit.note'] ?? null)->toBe('GoProperty lowered this limit on 25 December 2026, so you have longer than usual to apply.');

    // 30 days on, still within the 60: not restricted.
    $this->travelTo(CarbonImmutable::parse('2027-01-30 10:00', 'Africa/Lagos'));
    runTierMonitoring();
    expect(freshAcme()->status)->toBe(OrganizationStatus::Active);
});

it('counts a custom yearly limit instead of the tier\'s', function () {
    seedStaffRoles();
    app(TierOverrides::class)->set(staffNamed('Kemi', ['compliance-officer']), $this->acme, Money::naira(100_000_000)->kobo, Money::naira(5_000_000)->kobo, 'Large estate', '2027-06-01');

    tundePays(17); // ₦51m: over Tier 1's ₦50m, under the custom ₦100m
    runTierMonitoring();
    expect(flagsOf($this->acme))->toHaveCount(0);
});

it('records document expiry as flags beside the owners\' email', function () {
    $application = submittedApplication($this->acme, $this->owner);
    $id = inOrganization($this->acme, fn () => $application->documents()->where('type', 'proof_of_address')->sole());
    inOrganization($this->acme, fn () => $id->forceFill(['expires_at' => now()->addDays(10)->toDateString()])->save());

    app(DocumentExpiryCheck::class)->run();
    app(DocumentExpiryCheck::class)->run();

    $flag = flagsOf($this->acme, ComplianceFlagType::DocumentExpiring)->sole();
    expect($flag->subject_type)->toBe('compliance_document')
        ->and($flag->subject_id)->toBe($id->id)
        ->and($flag->owners_notified_at)->not->toBeNull();
});
