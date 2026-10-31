<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;
use Modules\SaasBilling\Jobs\MeterUnits;

require_once __DIR__.'/../../../../tests/Support/plans.php';

/*
 * The monthly bill quote the management handover's accept screen shows
 * (BILLING_PAYMENTS.md § 1.4, § 7.3; kept from P02-04's MonthlyBills): the
 * plan chosen at signup, else the cheapest for the units, priced for the
 * units the bill counts now, or with more; with a subscription, its next
 * billing day and the invoice that day brings (P06-06).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'signup_plan_key' => 'starter']);
    joinAs(User::factory()->create(), $this->acme);
    inOrganization($this->acme, function (): void {
        $building = Property::factory()->create();
        Unit::factory()->in($building)->count(30)->create();
        // Archived units are not counted (BILLING_PAYMENTS.md § 1.4).
        Unit::factory()->in($building)->create()->delete();
    });
});

it('works out the bill from the plan catalogue and the billable units, today and with more units', function () {
    $quotes = app(SubscriptionQuotes::class);

    $bill = $quotes->for($this->acme);
    expect($bill->plan->key)->toBe('starter')
        ->and($bill->units)->toBe(30)
        ->and($bill->amount->kobo)->toBe(2_400_000)
        ->and($bill->extraUnits())->toBe(20)
        ->and($bill->vatPercent)->toBe('7.5')
        ->and($bill->planChosen)->toBeTrue();

    $after = $quotes->for($this->acme, 5);
    expect($after->units)->toBe(35)
        ->and($after->amount->kobo)->toBe(2_750_000);

    // No plan chosen yet: the cheapest plan for the units (60 units → Growth, ₦40,000).
    $this->acme->forceFill(['signup_plan_key' => null])->save();
    $bill = $quotes->for($this->acme->fresh(), 30);
    expect($bill->plan->key)->toBe('growth')
        ->and($bill->amount->kobo)->toBe(4_000_000)
        ->and($bill->planChosen)->toBeFalse()
        ->and($quotes->cheapestFor(60)->plan->key)->toBe('growth');
});

it('names the next billing day: the 1st of next month, Lagos time', function () {
    $this->travelTo('2027-01-31 23:30:00');

    expect(app(SubscriptionQuotes::class)->nextBillingDay()->toDateString())->toBe('2027-03-01');

    $this->travelTo('2027-01-15 09:00:00');
    expect(app(SubscriptionQuotes::class)->nextBillingDay()->toDateString())->toBe('2027-02-01');
});

it('names an organization\'s own next billing day and the invoice it brings: the trial\'s end, the 1st, the yearly renewal (P06-06)', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-04 10:00', 'Africa/Lagos'));
    $quotes = app(SubscriptionQuotes::class);

    // In trial: the trial's last day, charged by days to the month's end (20–31 January, 12 of 31 days).
    startTrial($this->acme, 'starter', 16);
    $next = $quotes->for($this->acme)->next;
    expect($quotes->nextBillingDay($this->acme)->toDateString())->toBe('2027-01-20')
        ->and($next?->period())->toBe('20–31 January 2027')
        ->and($next?->daysBilled)->toBe(12)
        ->and($next?->subtotal->kobo)->toBe(intdiv(2_400_000 * 12 * 2 + 31, 31 * 2))
        ->and($next?->covered)->toBeFalse();

    // Monthly and active: the period runs to the month's end, so the 1st of next month, a full month.
    $beta = Organization::factory()->create(['slug' => 'beta-homes']);
    joinAs(User::factory()->create(), $beta);
    activeSubscription($beta, 'starter');
    app()->call([new MeterUnits, 'handle']);
    $next = $quotes->for($beta, 5)->next;
    expect($next?->billingDay->toDateString())->toBe('2027-02-01')
        ->and($next?->period())->toBe('1–28 February 2027')
        ->and($next?->isPartMonth())->toBeFalse()
        ->and($next?->subtotal->kobo)->toBe(1_000_000);

    // Yearly: the renewal, 12 months at 10 months' price.
    $gamma = Organization::factory()->create(['slug' => 'gamma-homes', 'platform_covered' => true]);
    joinAs(User::factory()->create(), $gamma);
    inOrganization($gamma, function (): void {
        $service = app(SubscriptionService::class);
        $service->start(null, planNamed('growth'), CarbonImmutable::now(), BillingCycle::Annual);
        $service->endTrial();
    });
    app()->call([new MeterUnits, 'handle']);
    $next = $quotes->for($gamma)->next;
    expect($quotes->nextBillingDay($gamma)->toDateString())->toBe('2028-01-04')
        ->and($next?->cycle)->toBe(BillingCycle::Annual)
        ->and($next?->monthsBilled)->toBe(10)
        ->and($next?->subtotal->kobo)->toBe(10 * planNamed('growth')->base->kobo)
        ->and($next?->covered)->toBeTrue();

    // No subscription: the 1st of next month.
    $delta = Organization::factory()->create(['slug' => 'delta-homes', 'signup_plan_key' => null]);
    expect($quotes->nextBillingDay($delta)->toDateString())->toBe('2027-02-01')
        ->and($quotes->for($delta)->next?->period())->toBe('1–28 February 2027');
});
