<?php

declare(strict_types=1);

use App\Subscriptions\SubscriptionService;
use Modules\OnlinePayments\Connections\ConnectionRefused;
use Modules\OnlinePayments\Connections\PaystackConnections;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Support/plans.php';

/*
 * P06-01b: the agency's own Paystack is on the Growth plan and above
 * (BILLING_PAYMENTS.md § 1.2; ONBOARDING_COMPLIANCE.md § 4.2 "the gate is
 * P06-01's"; docs/plans/P06-subtasks.md default 7). On Starter connecting
 * is refused before it runs (the Livewire hook) and by the service, the
 * page says which plan has it, and a saved connection is kept but tenants
 * are not offered online payment.
 */

beforeEach(function () {
    onlinePaymentsWorld($this);
    [$this->public, $this->secret] = paystackKeysOf('acme-homes');
    $this->paystack->know($this->secret);
});

afterEach(fn () => billsFreshRequest());

it('refuses connecting on Starter before Paystack is called, and says which plan has it', function () {
    startTrial($this->acme, 'starter');

    $snapshot = paystackSettingsSnapshot($this->owner);
    test()->actingAs($this->owner)->get('/o/acme-homes/settings/paystack')->assertOk()
        ->assertSee('Online payments through your own Paystack is on the Growth plan and above.')
        ->assertDontSee('data-test="keys-form"', false);

    paystackSettingsUpdate($this->owner, $snapshot, ['publicKey' => $this->public, 'secretKey' => $this->secret], [['connect']])->assertForbidden();
    expect(paystackConnectionOf($this->acme))->toBeNull()
        ->and($this->paystack->requestsTo('/transaction'))->toBe([]);

    expect(fn () => inOrganization($this->acme, fn () => app(PaystackConnections::class)->connect($this->owner, $this->public, $this->secret)))
        ->toThrow(ConnectionRefused::class, 'is on the Growth plan and above');
});

it('offers "Pay online" on Growth, and stops offering it after a move to Starter while keeping the connection', function () {
    startTrial($this->acme, 'growth');
    connectPaystack($this->paystack, $this->acme, $this->owner);
    payPage($this->bill->payment_reference)->assertOk()->assertSee('data-test="paystack-pay"', false);

    seedStaffRoles();
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan(staffNamed('Funmi', ['finance-admin']), planNamed('starter')));

    payPage($this->bill->payment_reference)->assertOk()->assertDontSee('data-test="paystack-pay"', false)->assertSee('0123456789');
    payOnlinePage(payOnlineLink($this->acme, $this->bill))->assertDontSee('Pay ₦850,000 online');
    expect(paystackConnectionOf($this->acme))->not->toBeNull();
});
