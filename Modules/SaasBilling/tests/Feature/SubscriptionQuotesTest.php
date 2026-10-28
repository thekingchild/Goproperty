<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;

/*
 * The monthly bill quote the management handover's accept screen shows
 * (BILLING_PAYMENTS.md § 1.4, § 7.3; kept from P02-04's MonthlyBills): the
 * plan chosen at signup, else the cheapest for the units, priced for the
 * units the bill counts now, or with more.
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
