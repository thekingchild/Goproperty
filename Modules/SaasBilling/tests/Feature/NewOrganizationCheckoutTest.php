<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Notification;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../../tests/Support/signup.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';
require_once __DIR__.'/metering-helpers.php';
require_once __DIR__.'/payment-helpers.php';

/*
 * P06-11 acceptance, second half: "checkout starts a subscription in
 * sandbox". A new organization picks Growth, billed yearly, on the pricing
 * page; signup carries the choice and the trial starts on it with no card
 * (the product owner's choice of 2026-11-02: ONBOARDING_COMPLIANCE.md § 2
 * step 4). When the trial ends the subscription is invoiced for the year and
 * the owner pays it through GoProperty's Paystack in test mode (P06-03),
 * simulated with Paystack's test-mode answers as the product owner decided
 * on 2026-10-30.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
});

afterEach(function () {
    exportsFreshRequest();
    app(OrganizationContext::class)->clear();
});

/** The "Start free trial" link of a plan on the pricing page, for the yearly toggle. */
function yearlyTrialLink(string $html, string $key): string
{
    $pattern = '/data-href-year="([^"]*plan='.preg_quote($key, '/').'[^"]*)"/';
    expect(preg_match($pattern, $html, $match))->toBe(1);

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
}

it('acceptance: pricing page → signup → trial on the chosen plan → first invoice paid in Paystack\'s test mode → active', function () {
    // The visitor picks Growth, yearly, on the pricing page.
    $link = yearlyTrialLink((string) $this->get('/pricing')->assertOk()->getContent(), 'growth');
    expect($link)->toBe(route('register', ['plan' => 'growth', 'cycle' => 'annual']));
    $this->get($link)->assertOk()->assertSee('Growth plan, billed yearly');

    // Signup: the plan step already holds Growth, yearly; saving it starts the trial. No card, no payment.
    $owner = signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan');
    signupStep($organization, 'setUpCollectionsLater');
    signupStep($organization, 'finish');

    $subscription = subscriptionOf($organization);
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->billing_cycle)->toBe(BillingCycle::Annual)
        ->and(planNamed('growth')->id)->toBe($subscription->plan_id)
        ->and(subscriptionInvoices($organization))->toBe([]);

    // The trial ends on 16 November with 60 units: one invoice for the year at 10 months' price.
    withUnits($organization, 60);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();

    [$invoice] = subscriptionInvoices($organization);
    $subtotal = 10 * (3_500_000 + 10 * 50_000);
    $total = $subtotal + expectedVat($subtotal);
    expect($invoice->only(['plan_name', 'billing_cycle', 'months_billed', 'units_billed', 'extra_units', 'status']))
        ->toEqual(['plan_name' => 'Growth', 'billing_cycle' => BillingCycle::Annual, 'months_billed' => 10, 'units_billed' => 60, 'extra_units' => 10, 'status' => InvoiceStatus::Open])
        ->and($invoice->period_start?->toDateString())->toBe('2026-11-16')
        ->and($invoice->period_end?->toDateString())->toBe('2027-11-15')
        ->and($invoice->subtotal_kobo->kobo)->toBe($subtotal)
        ->and($invoice->total_kobo->kobo)->toBe($total)
        ->and(subscriptionOf($organization)->status)->toBe(SubscriptionStatus::Active);

    // The owner pays at Paystack's checkout with GoProperty's test key; Paystack confirms by webhook.
    $reference = 'PINV-'.$invoice->number.'-a1';
    $http = subscriptionPaystack([$reference => ['success', $total]]);
    $response = payInvoice($owner, $invoice->number, $organization->slug)->assertOk();
    expect(LivewireHttp::redirect($response))->toBe('https://checkout.paystack.com/'.$reference);
    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.paystack.co/transaction/initialize'
        && $request->hasHeader('Authorization', 'Bearer sk_test_goproperty_platform')
        && $request['amount'] === (string) $total
        && $request['email'] === 'ada@adeyemi.ng');

    subscriptionWebhook($reference, amount: $total)->assertOk()->assertJson(['received' => true]);

    [$payment] = invoicePayments($organization, $invoice->number);
    expect(invoiceNumbered($organization, $invoice->number)->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->status)->toBe(PlatformPaymentStatus::Confirmed)
        ->and($payment->amount_kobo->kobo)->toBe($total)
        ->and(subscriptionOf($organization)->status)->toBe(SubscriptionStatus::Active)
        ->and(subscriptionOf($organization)->billing_cycle)->toBe(BillingCycle::Annual);
});

it('starts nothing billable at signup: no invoice and no Paystack call until the trial ends', function () {
    $http = fakePaystack([]);
    $this->get(route('register', ['plan' => 'scale', 'cycle' => 'annual']))->assertOk();

    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan');

    expect(subscriptionOf($organization)->status)->toBe(SubscriptionStatus::Trialing)
        ->and(subscriptionInvoices($organization))->toBe([]);
    $http->assertNothingSent();
    expect(User::query()->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(1);
});
