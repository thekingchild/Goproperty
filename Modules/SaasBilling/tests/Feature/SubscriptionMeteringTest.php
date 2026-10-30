<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\BillingCycle;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Subscriptions\Jobs\EndTrials;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\UnitEventReason;
use Modules\SaasBilling\Jobs\MeterUnits;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\SubscriptionUnitEvent;
use Modules\SaasBilling\Notifications\PlatformInvoiceIssued;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/*
 * P06-02: monthly metering, platform invoices and the VAT line
 * (BILLING_PAYMENTS.md § 1.4, § 3; docs/plans/P06-subtasks.md defaults
 * 13–20). Acceptance: "Rerunning metering creates one invoice per org per
 * period; VAT matches BILLING_PAYMENTS.md § 10" — VAT is 7.5 % of the
 * subtotal, its own line on every invoice (§ 1.4), rounded to the kobo.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bisi Admin']), $this->acme, OrganizationRole::Admin);
    withUnits($this->acme, 30);
});

afterEach(fn () => exportsFreshRequest());

/** Adds units to a building of the organization; archived ones are made too, and never counted (§ 1.4). */
function withUnits(Organization $organization, int $count): void
{
    inOrganization($organization, function () use ($count): void {
        $building = Property::query()->first() ?? Property::factory()->create();
        Unit::factory()->in($building)->count($count)->create();
        Unit::factory()->in($building)->create()->delete();
    });
}

function meterUnits(): void
{
    app()->call([new MeterUnits, 'handle']);
}

function endTrials(): void
{
    (new EndTrials)->handle(app(OrganizationContext::class), app(SubscriptionService::class));
}

/** @return list<PlatformInvoice> the organization's subscription invoices, oldest first */
function subscriptionInvoices(Organization $organization): array
{
    return inOrganization($organization, fn (): array => array_values(PlatformInvoice::query()->where('kind', InvoiceKind::Subscription)->orderBy('period_start')->get()->all()));
}

/** 7.5 % of the subtotal, rounded to the nearest kobo, halves up: worked out here independently of Money. */
function expectedVat(int $subtotalKobo): int
{
    return intdiv($subtotalKobo * 750 + 5_000, 10_000);
}

it('acceptance: rerunning metering creates one invoice per organization per period', function () {
    startTrial($this->acme, 'starter', 14);                          // ends 16 November 10:00
    $beta = Organization::factory()->create(['slug' => 'beta-homes', 'name' => 'Beta Homes']);
    joinAs(User::factory()->create(), $beta);
    withUnits($beta, 60);
    startTrial($beta, 'growth', 14);

    // In trial: nothing to bill, however often it runs.
    meterUnits();
    meterUnits();
    expect(subscriptionInvoices($this->acme))->toBe([]);

    // The trial ends: the first invoice is issued (units counted that day), for the rest of November.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:00', 'Africa/Lagos'));
    endTrials();
    endTrials();
    meterUnits();
    meterUnits();
    inOrganization($this->acme, fn () => app(SubscriptionMetering::class)->billDue());

    [$november] = subscriptionInvoices($this->acme);
    expect(subscriptionInvoices($this->acme))->toHaveCount(1)
        ->and(subscriptionInvoices($beta))->toHaveCount(1)
        ->and($november->number)->toBe('PR-2026-000001')
        ->and($november->status)->toBe(InvoiceStatus::Open)
        ->and($november->period_start?->toDateString())->toBe('2026-11-16')
        ->and($november->period_end?->toDateString())->toBe('2026-11-30')
        ->and($november->units_billed)->toBe(30)
        ->and($november->extra_units)->toBe(20)
        ->and([$november->days_billed, $november->days_in_month])->toBe([15, 30])
        ->and($november->subtotal_kobo->kobo)->toBe(1_200_000)            // ₦24,000 a month × 15/30
        ->and($november->due_date?->toDateString())->toBe('2026-11-23');

    $subscription = inOrganization($this->acme, fn (): Subscription => Subscription::query()->sole());
    expect($subscription->current_period_start?->toDateString())->toBe('2026-11-16')
        ->and($subscription->current_period_end?->toDateString())->toBe('2026-11-30')
        ->and($subscription->last_billed_units)->toBe(30);

    // Mid-month: units added, nothing billed until the next billing day (no proration, § 1.4).
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Africa/Lagos'));
    withUnits($this->acme, 5);
    meterUnits();
    expect(subscriptionInvoices($this->acme))->toHaveCount(1);

    // The 1st: one invoice for December, at the units counted that day, however often metering runs.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:50', 'Africa/Lagos'));
    meterUnits();
    meterUnits();
    inOrganization($this->acme, fn () => app(SubscriptionMetering::class)->billDue());
    $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00', 'Africa/Lagos'));
    meterUnits();

    $invoices = subscriptionInvoices($this->acme);
    expect($invoices)->toHaveCount(2)
        ->and(subscriptionInvoices($beta))->toHaveCount(2)
        ->and($invoices[1]->period_start?->toDateString())->toBe('2026-12-01')
        ->and($invoices[1]->period_end?->toDateString())->toBe('2026-12-31')
        ->and($invoices[1]->units_billed)->toBe(35)
        ->and($invoices[1]->days_billed)->toBeNull()
        ->and($invoices[1]->subtotal_kobo->kobo)->toBe(2_750_000);        // ₦10,000 + 25 × ₦700

    $this->travelTo(CarbonImmutable::parse('2027-01-01 01:45', 'Africa/Lagos'));
    meterUnits();
    meterUnits();
    expect(subscriptionInvoices($this->acme))->toHaveCount(3)
        ->and(subscriptionInvoices($this->acme)[2]->number)->toBe('PR-2027-000001');

    // The ledger: one row per billed period, why it changed; never changed.
    $ledger = inOrganization($this->acme, fn () => SubscriptionUnitEvent::query()->orderBy('effective_date')->get());
    expect($ledger->map(fn (SubscriptionUnitEvent $row): array => [$row->effective_date->toDateString(), $row->unit_count, $row->counted_units, $row->reason])->all())->toBe([
        ['2026-11-16', 30, 20, UnitEventReason::Initial],
        ['2026-12-01', 35, 25, UnitEventReason::UnitAdded],
        ['2027-01-01', 35, 25, UnitEventReason::Unchanged],
    ])
        ->and($ledger->pluck('platform_invoice_id')->all())->toBe(array_map(fn (PlatformInvoice $invoice): int => $invoice->id, subscriptionInvoices($this->acme)));
    expect(fn () => inOrganization($this->acme, fn () => $ledger->first()->forceFill(['unit_count' => 1])->save()))->toThrow(LogicException::class);

    // The database refuses a second invoice for a period, whatever writes it.
    expect(fn () => inOrganization($this->acme, fn () => DB::table('platform_invoices')->insert([
        'organization_id' => $this->acme->id, 'number' => 'PR-2027-999999', 'kind' => 'subscription', 'subscription_id' => $subscription->id,
        'period_start' => '2026-12-01', 'subtotal_kobo' => 1, 'vat_kobo' => 0, 'total_kobo' => 1, 'status' => 'open',
    ])))->toThrow(QueryException::class);

    // Each issue is one audit entry.
    expect(AuditEntry::query()->where('log_name', SubscriptionMetering::LOG)->where('event', 'platform_invoice.issued')->count())->toBe(6);
});

it('acceptance: VAT is 7.5 % of the subtotal, on its own line, rounded to the kobo', function () {
    // A first part month of 15 of 31 days: ₦24,000 × 15/31 = ₦11,612.9032 → ₦11,612.90; VAT ₦870.9675 → ₦870.97.
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Africa/Lagos'));
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-12-17 10:30', 'Africa/Lagos'));
    endTrials();

    [$december] = subscriptionInvoices($this->acme);
    expect([$december->days_billed, $december->days_in_month])->toBe([15, 31])
        ->and($december->subtotal_kobo->kobo)->toBe(1_161_290)
        ->and($december->vat_kobo->kobo)->toBe(87_097)
        ->and($december->total_kobo->kobo)->toBe(1_248_387)
        ->and($december->vat_percent)->toBe('7.5');

    // A full month: ₦24,000 + ₦1,800 VAT.
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:45', 'Africa/Lagos'));
    meterUnits();
    $january = subscriptionInvoices($this->acme)[1];
    expect([$january->subtotal_kobo->kobo, $january->vat_kobo->kobo, $january->total_kobo->kobo])->toBe([2_400_000, 180_000, 2_580_000]);

    // Every invoice: VAT = 7.5 % of the subtotal, total = subtotal + VAT.
    foreach (subscriptionInvoices($this->acme) as $invoice) {
        expect($invoice->vat_kobo->kobo)->toBe(expectedVat($invoice->subtotal_kobo->kobo))
            ->and($invoice->total_kobo->kobo)->toBe($invoice->subtotal_kobo->kobo + $invoice->vat_kobo->kobo);
    }

    // The VAT line is shown as its own line, with its rate, on the invoice email and Settings → Subscription.
    $text = (string) (new PlatformInvoiceIssued($this->acme->id, $january->id))->toMail($this->owner)->render();
    expect($text)->toContain('Starter plan, 10 units included (a month): ₦10,000')
        ->and($text)->toContain('20 extra units × ₦700 (a month): ₦14,000')
        ->and($text)->toContain('Subtotal: ₦24,000')
        ->and($text)->toContain('VAT (7.5%): ₦1,800')
        ->and($text)->toContain('Total: ₦25,800, due by 8 January 2027');

    exportPage($this->owner, '/settings/subscription')->assertOk()
        ->assertSeeInOrder(['Invoices', $january->number, 'Starter, 1–31 January 2027', '₦25,800', 'Waiting for payment', 'Due 8 January 2027', 'Units counted', '30', 'Subtotal', '₦24,000', 'VAT (7.5%)', '₦1,800', 'Total', '₦25,800'])
        ->assertSeeInOrder([$december->number, 'Starter, 17–31 December 2026', 'Subtotal for 15 of 31 days', '₦11,612.90', 'VAT (7.5%)', '₦870.97']);

    // A later VAT rate applies to later invoices only; an issued invoice keeps its own.
    config(['plans.vat_percent' => '10']);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 00:45', 'Africa/Lagos'));
    meterUnits();
    $invoices = subscriptionInvoices($this->acme);
    expect($invoices[1]->vat_kobo->kobo)->toBe(180_000)
        ->and($invoices[2]->vat_percent)->toBe('10')
        ->and($invoices[2]->vat_kobo->kobo)->toBe(240_000);
    expect(fn () => inOrganization($this->acme, fn () => $invoices[1]->forceFill(['vat_kobo' => $invoices[1]->vat_kobo->plus($invoices[1]->vat_kobo)])->save()))->toThrow(LogicException::class);
});

it('bills yearly subscriptions once a year, 12 months at 10 months\' price', function () {
    $growth = Organization::factory()->create(['slug' => 'beta-homes', 'name' => 'Beta Homes']);
    $growthOwner = joinAs(User::factory()->create(), $growth);
    withUnits($growth, 60);
    inOrganization($growth, fn () => app(SubscriptionService::class)->start(null, planNamed('growth'), CarbonImmutable::now()->addDays(14), BillingCycle::Annual));

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();
    [$year] = subscriptionInvoices($growth);
    expect($year->period_start?->toDateString())->toBe('2026-11-16')
        ->and($year->period_end?->toDateString())->toBe('2027-11-15')
        ->and($year->billing_cycle)->toBe(BillingCycle::Annual)
        ->and($year->months_billed)->toBe(10)
        ->and($year->days_billed)->toBeNull()
        ->and($year->subtotal_kobo->kobo)->toBe(40_000_000)            // (₦35,000 + 10 × ₦500) × 10
        ->and($year->vat_kobo->kobo)->toBe(3_000_000)
        ->and($year->total_kobo->kobo)->toBe(43_000_000);

    foreach (['2026-12-01 01:00', '2027-06-01 01:00', '2027-11-15 23:00'] as $moment) {
        $this->travelTo(CarbonImmutable::parse($moment, 'Africa/Lagos'));
        meterUnits();
    }
    expect(subscriptionInvoices($growth))->toHaveCount(1);

    $this->travelTo(CarbonImmutable::parse('2027-11-16 00:45', 'Africa/Lagos'));
    meterUnits();
    meterUnits();
    $renewal = subscriptionInvoices($growth)[1];
    expect(subscriptionInvoices($growth))->toHaveCount(2)
        ->and($renewal->period_start?->toDateString())->toBe('2027-11-16')
        ->and($renewal->period_end?->toDateString())->toBe('2028-11-15');

    exportPage($growthOwner, '/settings/subscription', 'beta-homes')
        ->assertOk()->assertSeeInOrder(['Beta Homes', 'Growth, 16 November 2027 – 15 November 2028', '₦430,000', 'Subtotal for 12 months, priced as 10', '₦400,000']);
});

it('bills at the plan\'s terms on the period\'s first day: a scheduled price reaches existing customers on their next billing day', function () {
    seedStaffRoles();
    $finance = staffNamed('Funmi', ['finance-admin']);
    activeSubscription($this->acme, 'starter');                     // invoiced 2–30 November at today's terms

    planEditor()->schedule($finance, SubscriptionPlan::query()->where('key', 'starter')->sole(), termsLike('starter', 1_200_000), CarbonImmutable::parse('2027-01-01', 'Africa/Lagos'), 'Inflation');

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:45', 'Africa/Lagos'));
    meterUnits();
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:45', 'Africa/Lagos'));
    meterUnits();

    [$november, $december, $january] = subscriptionInvoices($this->acme);
    expect([$november->base_price_kobo?->kobo, $december->base_price_kobo?->kobo, $january->base_price_kobo?->kobo])->toBe([1_000_000, 1_000_000, 1_200_000])
        ->and($december->subtotal_kobo->kobo)->toBe(2_400_000)
        ->and($january->subtotal_kobo->kobo)->toBe(2_600_000)
        ->and($january->plan_version_id)->not->toBe($december->plan_version_id)
        ->and([$november->days_billed, $november->days_in_month])->toBe([29, 30])
        ->and(inOrganization($this->acme, fn () => SubscriptionUnitEvent::query()->orderByDesc('effective_date')->first()?->reason))->toBe(UnitEventReason::PlanChange);
});

it('tells the owners once, by email and in the app, and marks a platform-covered organization\'s invoice paid without asking', function () {
    $phoneOnly = joinAs(User::factory()->create(['email' => null, 'phone' => '+2348030000009']), $this->acme);
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();
    meterUnits();

    [$invoice] = subscriptionInvoices($this->acme);
    Notification::assertSentToTimes($this->owner, PlatformInvoiceIssued::class, 1);
    Notification::assertSentToTimes($phoneOnly, PlatformInvoiceIssued::class, 1);
    Notification::assertNotSentTo($this->admin, PlatformInvoiceIssued::class);

    $notice = new PlatformInvoiceIssued($this->acme->id, $invoice->id);
    expect($notice->via($this->owner))->toBe(['database', 'mail'])
        ->and($notice->via($phoneOnly))->toBe(['database']);
    $mail = $notice->toMail($this->owner);
    expect($mail->subject)->toBe("GoProperty invoice {$invoice->number}: ₦12,900 due 23 November 2026")
        ->and((string) $mail->render())->toContain('Subtotal for 15 of 30 days: ₦12,000')
        ->and((string) $mail->render())->toContain('16–30 November 2026, counting 30 units')
        ->and((string) $mail->render())->toContain('/o/acme-homes/settings/subscription');

    // A platform-covered organization (signup sets it for landlords, § 2): paid when issued, audited, nobody asked to pay.
    $landlord = Organization::factory()->create(['slug' => 'eze-properties', 'name' => 'Eze Properties', 'platform_covered' => true]);
    $landlordOwner = joinAs(User::factory()->create(), $landlord);
    withUnits($landlord, 4);
    activeSubscription($landlord, 'starter');

    [$covered] = subscriptionInvoices($landlord);
    expect($covered->status)->toBe(InvoiceStatus::Paid)
        ->and($covered->paid_at)->not->toBeNull()
        ->and($covered->notes)->toBe(SubscriptionMetering::COVERED_NOTE)
        ->and($covered->total_kobo->kobo)->toBeGreaterThan(0);
    Notification::assertNotSentTo($landlordOwner, PlatformInvoiceIssued::class);
    expect(AuditEntry::query()->where('log_name', SubscriptionMetering::LOG)->where('event', 'platform_invoice.covered')->sole()->subject_id)->toBe($covered->id);
    exportPage($landlordOwner, '/settings/subscription', 'eze-properties')->assertOk()->assertSee('Covered by GoProperty');

    // A payer that is platform-covered covers the organization's invoices too.
    $agency = Organization::factory()->create(['slug' => 'kola-homes', 'name' => 'Kola Homes']);
    joinAs(User::factory()->create(), $agency);
    activeSubscription($agency, 'starter');
    inOrganization($agency, fn () => DB::table('subscriptions')->where('organization_id', $agency->id)->update(['payer_organization_id' => $landlord->id]));
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:45', 'Africa/Lagos'));
    meterUnits();
    expect(subscriptionInvoices($agency)[1]->status)->toBe(InvoiceStatus::Paid);
});

it('bills only active and past-due subscriptions, and never the months a customer was suspended', function () {
    activeSubscription($this->acme, 'starter');                      // November billed from the 2nd
    $late = Organization::factory()->create(['slug' => 'late-homes', 'name' => 'Late Homes']);
    joinAs(User::factory()->create(), $late);
    activeSubscription($late, 'starter');
    $trial = Organization::factory()->create(['slug' => 'trial-homes', 'name' => 'Trial Homes']);
    joinAs(User::factory()->create(), $trial);
    startTrial($trial, 'starter', 60);

    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'Africa/Lagos'));
    inOrganization($late, fn () => app(SubscriptionService::class)->markPastDue());
    inOrganization($this->acme, function (): void {
        app(SubscriptionService::class)->markPastDue();
        app(SubscriptionService::class)->startGrace();
        app(SubscriptionService::class)->suspend();
    });

    // 1 December: the past-due customer is billed; the suspended one and the trial are not.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:45', 'Africa/Lagos'));
    meterUnits();
    expect(subscriptionInvoices($late))->toHaveCount(2)
        ->and(subscriptionInvoices($this->acme))->toHaveCount(1)
        ->and(subscriptionInvoices($trial))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:45', 'Africa/Lagos'));
    meterUnits();
    expect(subscriptionInvoices($this->acme))->toHaveCount(1);

    // Paid on 10 January: active again, billed at once from that day, by days; December was never billed.
    $this->travelTo(CarbonImmutable::parse('2027-01-10 14:00', 'Africa/Lagos'));
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->activate(null, 'Paid'));
    meterUnits();

    $invoices = subscriptionInvoices($this->acme);
    expect($invoices)->toHaveCount(2)
        ->and($invoices[1]->period_start?->toDateString())->toBe('2027-01-10')
        ->and($invoices[1]->period_end?->toDateString())->toBe('2027-01-31')
        ->and([$invoices[1]->days_billed, $invoices[1]->days_in_month])->toBe([22, 31]);
});

it('schedules saas-meter-units hourly on the billing queue, in-process', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'saas-meter-units');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('45 * * * *')
        ->and((new MeterUnits)->queue)->toBe('billing');
});
