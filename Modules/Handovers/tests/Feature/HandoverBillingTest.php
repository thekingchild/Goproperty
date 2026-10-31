<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\BillingCycle;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\BillChange;
use Modules\Handovers\Handovers\HandoverQuotes;
use Modules\Handovers\Models\ManagementHandover;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\UnitEventReason;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\SubscriptionUnitEvent;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../SaasBilling/tests/Feature/metering-helpers.php';

/*
 * P06-06: the management handover on live subscriptions
 * (BILLING_PAYMENTS.md § 7.3, § 3, § 1.4). The accept screen reads the
 * receiving organization's real subscription: its plan at the terms in
 * effect on its next billing day, that day (the 1st, a yearly renewal, the
 * trial's end) and the invoice it brings, before and after the handed-over
 * units. Acceptance: "The accept screen's amounts equal what the next
 * metering run bills for a fixture agency before and after a handover; the
 * landlord's organization stops counting the moved units on its next
 * billing day." No proration (§ 1.4): the moved units are billed from the
 * next billing day, by whichever organization has them then.
 *
 * The light world (helpers.php), 4 January 2027: Adeyemi Realty (agency,
 * Starter: ₦10,000 for 10 units, ₦700 each over) has 9 units; Palm Court
 * (3 units) and Lagoon View (2) are offered, so its bill goes from ₦10,000
 * to ₦12,800 a month.
 */

beforeEach(fn () => handoverLightWorld());

afterEach(fn () => handoversFreshRequest());

/** The bill change the accept screen shows the agency now. */
function screenChange(ManagementHandover $handover): BillChange
{
    catalogue();
    $handover = storedHandover($handover);
    $quotes = app(HandoverQuotes::class);

    return $quotes->change($handover->toOrganization, array_sum($quotes->unitsIn($handover->organization, $handover->propertyIds())));
}

/** The organization's subscription invoice for the period starting on $start. */
function invoiceFrom(Organization $organization, string $start): PlatformInvoice
{
    return collect(subscriptionInvoices($organization))->sole(fn (PlatformInvoice $invoice): bool => $invoice->period_start?->toDateString() === $start);
}

/** The invoice's amounts, as the screen's next invoice gives them. */
function invoiceAmounts(PlatformInvoice $invoice): array
{
    return ['units' => $invoice->units_billed, 'subtotal' => $invoice->subtotal_kobo->kobo, 'vat' => $invoice->vat_kobo->kobo, 'total' => $invoice->total_kobo->kobo, 'day' => $invoice->period_start?->toDateString()];
}

/** @return array{units: int, subtotal: int, vat: int, total: int, day: string} */
function shownAmounts(BillChange $change, bool $after = true): array
{
    $bill = $after ? $change->after : $change->before;
    $next = $bill->next ?? throw new LogicException('No next invoice.');

    return ['units' => $bill->units, 'subtotal' => $next->subtotal->kobo, 'vat' => $next->vat->kobo, 'total' => $next->total->kobo, 'day' => $next->periodStart->toDateString()];
}

function handoverLagos(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Africa/Lagos'));
}

it('acceptance: the accept screen\'s amounts equal what the next metering run bills before and after a handover, and the landlord stops counting the moved units on its next billing day', function () {
    // A paying landlord (hybrid, not covered) keeps Cedar House (2 units): 7 units in all.
    $this->landlord->forceFill(['type' => OrganizationType::Hybrid, 'platform_covered' => false])->save();
    $cedar = buildingIn($this->landlord, ['name' => 'Cedar House', 'slug' => 'cedar-house', 'address' => '9 Bourdillon Road, Ikoyi']);
    unitIn($this->landlord, $cedar, ['unit_label' => 'Unit A']);
    unitIn($this->landlord, $cedar, ['unit_label' => 'Unit B']);

    // Both on live monthly Starter subscriptions, January billed by days.
    activeSubscription($this->agency, 'starter');
    activeSubscription($this->landlord, 'starter');
    meterUnits();
    expect(invoiceFrom($this->agency, '2027-01-04')->units_billed)->toBe(9)
        ->and(invoiceFrom($this->landlord, '2027-01-04')->units_billed)->toBe(7);

    // Before: an offer the agency declines. The screen's "before" is February's invoice.
    $first = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    handoversPage($this->ada, 'acme-homes', "/{$first->id}")->assertOk()
        ->assertSee('Your GoProperty bill goes from ₦10,000/month to ₦12,800/month (before VAT) from your next billing day, 1 February 2027.', false)
        ->assertSee('Your next invoice, on 1 February 2027 for 1–28 February 2027 (a full month), goes from ₦10,000 to ₦12,800 before VAT: ₦13,760 with 7.5% VAT.', false);
    $declinedScreen = screenChange($first);
    declineHandover($this->ada, $this->agency, incomingHandover($this->agency, $first));

    handoverLagos('2027-02-01 00:50');
    meterUnits();
    expect(invoiceAmounts(invoiceFrom($this->agency, '2027-02-01')))->toBe(shownAmounts($declinedScreen, after: false))
        ->and(invoiceAmounts(invoiceFrom($this->agency, '2027-02-01')))->toBe(['units' => 9, 'subtotal' => 1_000_000, 'vat' => 75_000, 'total' => 1_075_000, 'day' => '2027-02-01'])
        ->and(invoiceFrom($this->landlord, '2027-02-01')->units_billed)->toBe(7);

    // After: a new offer mid-February, accepted on the screen. Its amounts are March's invoice.
    handoverLagos('2027-02-10 11:00');
    $second = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    $screen = handoversPage($this->ada, 'acme-homes', "/{$second->id}")->assertOk()
        ->assertSee('from your next billing day, 1 March 2027.', false)
        ->assertSee('Your next invoice, on 1 March 2027 for 1–31 March 2027 (a full month), goes from ₦10,000 to ₦12,800 before VAT: ₦13,760 with 7.5% VAT.', false);
    $shown = screenChange($second);
    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($screen), [], [['accept']])->assertOk();
    expect(storedHandover($second)->status)->toBe(HandoverStatus::Active);

    // No proration: nothing is billed or credited until the next billing day.
    meterUnits();
    expect(subscriptionInvoices($this->agency))->toHaveCount(2)
        ->and(subscriptionInvoices($this->landlord))->toHaveCount(2)
        ->and(invoiceFrom($this->agency, '2027-02-01')->units_billed)->toBe(9)
        ->and(invoiceFrom($this->landlord, '2027-02-01')->units_billed)->toBe(7);

    handoverLagos('2027-03-01 00:50');
    meterUnits();
    $march = invoiceFrom($this->agency, '2027-03-01');
    expect(invoiceAmounts($march))->toBe(shownAmounts($shown))
        ->and(invoiceAmounts($march))->toBe(['units' => 14, 'subtotal' => 1_280_000, 'vat' => 96_000, 'total' => 1_376_000, 'day' => '2027-03-01'])
        ->and($march->subtotal_kobo->kobo)->toBe(storedHandover($second)->monthly_after_kobo?->kobo)
        ->and($march->status)->toBe(InvoiceStatus::Open);

    // The landlord's organization counts only Cedar House from its next billing day.
    expect(invoiceFrom($this->landlord, '2027-03-01')->units_billed)->toBe(2)
        ->and(inOrganization($this->landlord, fn () => SubscriptionUnitEvent::query()->whereDate('effective_date', '2027-03-01')->sole()->reason))->toBe(UnitEventReason::UnitRemoved)
        ->and(inOrganization($this->agency, fn () => SubscriptionUnitEvent::query()->whereDate('effective_date', '2027-03-01')->sole()->reason))->toBe(UnitEventReason::UnitAdded);

    // The acceptance's audit entry names the billing day and the invoice it brings.
    $entry = inOrganization($this->landlord, fn () => AuditEntry::query()->where('event', 'handover.accepted')->sole());
    expect($entry->properties['next_billing_day'])->toBe('2027-03-01')
        ->and($entry->properties['next_invoice_subtotal_before_kobo'])->toBe(1_000_000)
        ->and($entry->properties['next_invoice_subtotal_after_kobo'])->toBe(1_280_000);
});

it('shows the trial\'s end as the billing day, charged by days, as metering bills it', function () {
    startTrial($this->agency, 'starter', 16);                       // ends 20 January 2027
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);

    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('from your next billing day, 20 January 2027.', false)
        ->assertSee('Your next invoice, on 20 January 2027 for 20–31 January 2027 (12 of 31 days), goes from ₦3,870.97 to ₦4,954.84 before VAT: ₦5,326.45 with 7.5% VAT.', false);
    $shown = screenChange($handover);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));

    handoverLagos('2027-01-20 11:00');
    endTrials();
    meterUnits();
    expect(invoiceAmounts(invoiceFrom($this->agency, '2027-01-20')))->toBe(shownAmounts($shown))
        ->and(shownAmounts($shown))->toBe(['units' => 14, 'subtotal' => 495_484, 'vat' => 37_161, 'total' => 532_645, 'day' => '2027-01-20']);
});

it('shows a yearly subscription\'s renewal date and a year at 10 months\' price, as metering bills it', function () {
    inOrganization($this->agency, function (): void {
        $service = app(SubscriptionService::class);
        $service->start(null, planNamed('starter'), CarbonImmutable::now(), BillingCycle::Annual);
        $service->endTrial();
    });
    meterUnits();                                                   // 4 January 2027 – 3 January 2028
    handoverLagos('2027-06-15 10:00');
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);

    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('from your next billing day, 4 January 2028.', false)
        ->assertSee('Your next invoice, on 4 January 2028 for 4 January 2028 – 3 January 2029 (a year at 10 months\' price), goes from ₦100,000 to ₦128,000 before VAT: ₦137,600 with 7.5% VAT.');
    $shown = screenChange($handover);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));

    handoverLagos('2027-12-31 10:00');
    meterUnits();
    expect(subscriptionInvoices($this->agency))->toHaveCount(1);

    handoverLagos('2028-01-04 00:50');
    meterUnits();
    expect(invoiceAmounts(invoiceFrom($this->agency, '2028-01-04')))->toBe(shownAmounts($shown))
        ->and(shownAmounts($shown)['subtotal'])->toBe(12_800_000);
});

it('prices the next billing day at the plan\'s terms in effect that day, a scheduled price included, as metering bills it', function () {
    seedStaffRoles();
    activeSubscription($this->agency, 'starter');
    meterUnits();
    // ₦1,000 per extra unit from 1 March (saved 4 January: 30 days' notice given).
    planEditor()->schedule(staffNamed('Funmi', ['finance-admin']), SubscriptionPlan::query()->where('key', 'starter')->sole(), termsLike('starter', unitKobo: 100_000), CarbonImmutable::parse('2027-03-01', 'Africa/Lagos'), 'Costs');

    // In January the next billing day (1 February) is still on today's terms.
    $january = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    expect(shownAmounts(screenChange($january))['subtotal'])->toBe(1_280_000);
    declineHandover($this->ada, $this->agency, incomingHandover($this->agency, $january));

    // In February the next billing day is 1 March: the new terms.
    handoverLagos('2027-02-01 00:50');
    meterUnits();
    handoverLagos('2027-02-10 11:00');
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('Your GoProperty bill goes from ₦10,000/month to ₦14,000/month (before VAT) from your next billing day, 1 March 2027.', false)
        ->assertSee('plus ₦1,000 for each unit over them', false);
    $shown = screenChange($handover);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));
    expect(storedHandover($handover)->monthly_after_kobo?->kobo)->toBe(1_400_000);

    handoverLagos('2027-03-01 00:50');
    meterUnits();
    expect(invoiceAmounts(invoiceFrom($this->agency, '2027-03-01')))->toBe(shownAmounts($shown))
        ->and(shownAmounts($shown)['subtotal'])->toBe(1_400_000);
});

it('tells a platform-covered organization it has nothing to pay, and metering issues the invoice paid', function () {
    $this->agency->forceFill(['platform_covered' => true])->save();
    activeSubscription($this->agency, 'starter');
    meterUnits();
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);

    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('GoProperty covers your subscription: your next invoice, on 1 February 2027, is marked paid when it is issued, with nothing to pay.', false)
        ->assertDontSee('Your next invoice, on', false);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));

    handoverLagos('2027-02-01 00:50');
    meterUnits();
    expect(invoiceFrom($this->agency, '2027-02-01')->status)->toBe(InvoiceStatus::Paid)
        ->and(invoiceFrom($this->agency, '2027-02-01')->units_billed)->toBe(14);
});
