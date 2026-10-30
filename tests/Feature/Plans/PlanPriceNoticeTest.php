<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\PlanChangeNotice;
use App\Models\PlanVersion;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\PlanTermsChanging;
use App\Plans\Feature;
use App\Plans\Features;
use App\Plans\FeatureValue;
use App\Platform\Plans\PlanChangeRefused;
use App\Platform\Plans\SendPlanChangeNotices;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;

require_once __DIR__.'/../../Support/plans.php';
require_once __DIR__.'/../Exports/helpers.php';

/*
 * P06-01 acceptance: "Price change cannot take effect before 30 days'
 * notice" (BILLING_PAYMENTS.md § 1.3; docs/plans/P06-subtasks.md defaults
 * 3–5). A new version of a plan that customers are on starts no sooner than
 * 30 days after it is saved; saving tells every organization on the plan,
 * once, by email and in the app; existing customers keep their terms until
 * their first billing period from that date.
 */

beforeEach(function () {
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->finance = staffNamed('Funmi', ['finance-admin']);

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->beta = Organization::factory()->create(['slug' => 'beta-homes', 'name' => 'Beta Homes']);
    $this->gamma = Organization::factory()->create(['slug' => 'gamma-homes', 'name' => 'Gamma Homes']);
    $this->delta = Organization::factory()->create(['slug' => 'delta-homes', 'name' => 'Delta Homes']);
    $this->acmeOwner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->acmeAdmin = joinAs(User::factory()->create(['name' => 'Bisi Admin', 'email' => null, 'phone' => '+2348030000001']), $this->acme, OrganizationRole::Admin);
    $this->acmeAgent = joinAs(User::factory()->create(['name' => 'Chidi Agent']), $this->acme, OrganizationRole::Agent);
    $this->betaOwner = joinAs(User::factory()->create(), $this->beta);
    $this->gammaOwner = joinAs(User::factory()->create(), $this->gamma);
    $this->deltaOwner = joinAs(User::factory()->create(), $this->delta);

    activeSubscription($this->acme, 'growth');                 // period from 2 November 2026
    startTrial($this->beta, 'growth', 14);                     // in trial
    activeSubscription($this->gamma, 'starter');               // another plan
    activeSubscription($this->delta, 'growth');
    inOrganization($this->delta, fn () => app(SubscriptionService::class)->cancel(null, 'Closed the agency'));
});

afterEach(fn () => exportsFreshRequest());

it('acceptance: refuses any date less than 30 days after the save for a plan with customers, at any level', function () {
    $growth = planNamed('growth');
    $plan = SubscriptionPlan::query()->findOrFail($growth->id);

    // Acme and Beta count; Delta is cancelled; Gamma is on Starter.
    expect(planEditor()->customers($growth->id))->toBe(2)
        ->and(planEditor()->earliestDate($plan)->toDateString())->toBe('2026-12-02')
        ->and(planEditor()->suggestedDate($plan)->toDateString())->toBe('2027-01-01');

    foreach (['2026-11-02', '2026-11-20', '2026-12-01'] as $tooSoon) {
        expect(fn () => planEditor()->schedule($this->finance, $plan, termsLike('growth', 4_000_000), CarbonImmutable::parse($tooSoon, 'Africa/Lagos'), 'Inflation'))
            ->toThrow(PlanChangeRefused::class, 'Growth has 2 customers who must be told 30 days ahead: the earliest date is 2 December 2026.');
    }

    expect(PlanVersion::query()->where('plan_id', $growth->id)->count())->toBe(1);
    Notification::assertNothingSent();

    // Day 30 is allowed.
    $version = planEditor()->schedule($this->finance, $plan, termsLike('growth', 4_000_000), CarbonImmutable::parse('2026-12-02', 'Africa/Lagos'), 'Inflation');
    expect($version->version)->toBe(2)
        ->and($version->effective_from->toDateString())->toBe('2026-12-02')
        ->and($version->notice_given_at?->toIso8601String())->toBe(now()->toIso8601String());

    // One scheduled version at a time: no second one, earlier or later.
    expect(fn () => planEditor()->schedule($this->finance, $plan, termsLike('growth', 3_000_000), CarbonImmutable::parse('2027-01-01', 'Africa/Lagos'), 'Again'))
        ->toThrow(PlanChangeRefused::class, 'Version 2 of Growth is already scheduled for 2 December 2026. Cancel it first.');

    // Cancelling and rescheduling does not restart the clock from an older save.
    planEditor()->cancel($this->finance, $version, 'Typo in the price');
    $this->travel(10)->days();
    expect(fn () => planEditor()->schedule($this->finance, $plan, termsLike('growth', 4_000_000), CarbonImmutable::parse('2026-12-02', 'Africa/Lagos'), 'Inflation'))
        ->toThrow(PlanChangeRefused::class, 'the earliest date is 12 December 2026.');

    // Only plans.manage may schedule at all.
    expect(fn () => planEditor()->schedule(staffNamed('Sola', ['support-agent']), $plan, termsLike('growth', 4_000_000), CarbonImmutable::parse('2027-02-01', 'Africa/Lagos'), 'x'))
        ->toThrow(PlanChangeRefused::class, 'You do not have permission to edit plans and prices.');
});

it('acceptance: tells each organization on the plan once, by email and in the app, at the save', function () {
    $growth = SubscriptionPlan::query()->where('key', 'growth')->sole();
    $version = planEditor()->schedule($this->finance, $growth, termsLike('growth', 4_000_000, 60_000, [
        Feature::StaffUsers->value => FeatureValue::limit(12),
    ]), CarbonImmutable::parse('2027-01-01', 'Africa/Lagos'), 'Costs went up');

    // The job ran at the commit (sync queue); running it again tells nobody twice.
    (new SendPlanChangeNotices($version->id))->handle(app(OrganizationContext::class), app(OrganizationOwners::class));

    $told = PlanChangeNotice::query()->withoutGlobalScopes()->orderBy('organization_id')->pluck('organization_id')->all();
    expect($told)->toBe([$this->acme->id, $this->beta->id]);

    Notification::assertSentToTimes($this->acmeOwner, PlanTermsChanging::class, 1);
    Notification::assertSentToTimes($this->acmeAdmin, PlanTermsChanging::class, 1);
    Notification::assertSentToTimes($this->betaOwner, PlanTermsChanging::class, 1);
    Notification::assertNotSentTo([$this->acmeAgent, $this->gammaOwner, $this->deltaOwner], PlanTermsChanging::class);

    // The email says what changes, when, and the bill before and after (no units yet: the base).
    $notice = new PlanTermsChanging($this->acme->id, $version->id);
    expect($notice->via($this->acmeOwner))->toBe(['database', 'mail'])
        ->and($notice->via($this->acmeAdmin))->toBe(['database']);
    $mail = $notice->toMail($this->acmeOwner);
    $text = (string) $mail->render();
    expect($mail->subject)->toBe('Your Growth plan changes on 1 January 2027')
        ->and($text)->toContain('Base price: ₦35,000 → ₦40,000 a month')
        ->and($text)->toContain('Each extra unit: ₦500 → ₦600 a month')
        ->and($text)->toContain('Staff users: 10 → 12')
        ->and($text)->toContain('goes from ₦35,000 to ₦40,000 before VAT')
        ->and($text)->toContain('/o/acme-homes/settings/subscription')
        ->and($notice->toArray($this->acmeOwner)['message'])->toBe('Your Growth plan changes on 1 January 2027');

    $entry = AuditEntry::query()->where('log_name', 'plans')->where('event', 'plan.version_scheduled')->sole();
    expect($entry->causer_id)->toBe($this->finance->id)
        ->and($entry->properties['customers_told'])->toBe(2)
        ->and($entry->properties['effective_from'])->toBe('2027-01-01');
});

it('acceptance: keeps everyone on the old terms until the date, and existing customers until their next billing period', function () {
    $growth = SubscriptionPlan::query()->where('key', 'growth')->sole();
    planEditor()->schedule($this->finance, $growth, termsLike('growth', 4_000_000, features: [
        Feature::WhatsApp->value => FeatureValue::off(),
    ]), CarbonImmutable::parse('2027-01-01', 'Africa/Lagos'), 'Costs went up');

    $features = fn (): Features => app(Features::class);
    $base = fn (Organization $organization): int => $features()->planFor($organization)?->base->kobo ?? 0;

    // The day before: old terms for the landing page, the trial and the active customer.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59', 'Africa/Lagos'));
    catalogue();
    expect(planNamed('growth')->base->kobo)->toBe(3_500_000)
        ->and($base($this->beta))->toBe(3_500_000)
        ->and($base($this->acme))->toBe(3_500_000)
        ->and($features()->enabled(Feature::WhatsApp, $this->beta))->toBeTrue();

    // From the date: new signups and the trial get the new terms...
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00', 'Africa/Lagos'));
    catalogue();
    expect(planNamed('growth')->base->kobo)->toBe(4_000_000)
        ->and($base($this->beta))->toBe(4_000_000)
        ->and($features()->enabled(Feature::WhatsApp, $this->beta))->toBeFalse();

    // ...while Acme keeps its terms through the billing period it is in (it started on 2 November), quote included.
    expect($base($this->acme))->toBe(3_500_000)
        ->and($features()->enabled(Feature::WhatsApp, $this->acme))->toBeTrue()
        ->and(app(SubscriptionQuotes::class)->for($this->acme)->amount->kobo)->toBe(3_500_000);
});

it('lets a plan nobody is on change from today, without notices', function () {
    $scale = SubscriptionPlan::query()->where('key', 'scale')->sole();
    expect(planEditor()->earliestDate($scale)->toDateString())->toBe('2026-11-02');

    $version = planEditor()->schedule($this->finance, $scale, termsLike('scale', 9_000_000), CarbonImmutable::parse('2026-11-02', 'Africa/Lagos'), 'Launch adjustment');

    expect($version->notice_given_at)->toBeNull()
        ->and(planNamed('scale')->base->kobo)->toBe(9_000_000);
    Notification::assertNothingSent();
    expect(fn () => planEditor()->schedule($this->finance, $scale, termsLike('scale', 9_000_000), CarbonImmutable::parse('2026-11-01', 'Africa/Lagos'), 'x'))
        ->toThrow(PlanChangeRefused::class, 'The date cannot be in the past.');
});

it('shows the change on every workspace page to owners and administrators until it applies, and not once cancelled', function () {
    $growth = SubscriptionPlan::query()->where('key', 'growth')->sole();
    $version = planEditor()->schedule($this->finance, $growth, termsLike('growth', 4_000_000), CarbonImmutable::parse('2027-01-01', 'Africa/Lagos'), 'Costs went up');

    exportPage($this->acmeOwner, '')->assertOk()
        ->assertSee('Your Growth plan changes on 1 January 2027')
        ->assertSee('Base price: ₦35,000 → ₦40,000 a month');
    exportPage($this->acmeAgent, '')->assertOk()->assertDontSee('plan changes on');
    exportPage($this->gammaOwner, '', 'gamma-homes')->assertOk()->assertDontSee('plan changes on');

    planEditor()->cancel($this->finance, $version, 'Changed our mind');
    exportPage($this->acmeOwner, '')->assertOk()->assertDontSee('plan changes on');
    expect(planNamed('growth')->base->kobo)->toBe(3_500_000);

    // A cancelled version is never in effect, even after its date.
    $this->travelTo(CarbonImmutable::parse('2027-01-02 09:00', 'Africa/Lagos'));
    expect(planNamed('growth')->base->kobo)->toBe(3_500_000)
        ->and(fn () => planEditor()->cancel($this->finance, $version->refresh(), 'again'))->toThrow(PlanChangeRefused::class, 'That version is already cancelled.');
});
