<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Platform\Filament\Resources\PaystackWebhooks\Pages\ListPaystackWebhooks;
use App\Platform\Impersonation\ImpersonationMode;
use App\Platform\Webhooks\PaystackWebhookDelivery;
use App\Platform\Webhooks\WebhookReplay;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Jobs\ReconcilePlatformPayments;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Notifications\PlatformPaymentReceived;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';
require_once __DIR__.'/metering-helpers.php';
require_once __DIR__.'/payment-helpers.php';

/*
 * P06-03: paying subscription invoices through GoProperty's own Paystack
 * (BILLING_PAYMENTS.md § 4; docs/plans/P06-subtasks.md "P06-03"). No card
 * is kept: each invoice is paid at checkout (the product owner's decision of
 * 2026-10-30). Acceptance: "Sandbox end-to-end: invoice → charge → webhook →
 * paid → active. Same event twice produces one payment; forged-signature
 * webhook is dropped and logged" — Paystack simulated with its test-mode
 * answers (the product owner's decision: no test key here).
 *
 * The world: Acme on Starter with 30 units; its trial ends on 16 November,
 * invoice PR-2026-000001 for 16–30 November: ₦12,000 + ₦900 VAT = ₦12,900,
 * due 23 November.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@adeyemi.ng']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bisi Admin']), $this->acme, OrganizationRole::Admin);
    withUnits($this->acme, 30);
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();
});

afterEach(fn () => exportsFreshRequest());

it('acceptance: invoice → checkout → webhook → paid → active, in Paystack\'s test mode; the same event twice makes one payment', function () {
    $reference = 'PINV-'.FIRST_INVOICE.'-a1';
    $http = subscriptionPaystack([$reference => ['success', FIRST_INVOICE_KOBO]]);

    // The invoice is overdue: the subscription is past due.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 09:00', 'Africa/Lagos'));
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->markPastDue());
    expect(invoiceNumbered($this->acme, FIRST_INVOICE)->total_kobo->kobo)->toBe(FIRST_INVOICE_KOBO);

    // The owner presses Pay: a pending payment for exactly the total and Paystack's checkout, with GoProperty's test key.
    $response = payInvoice($this->owner)->assertOk();
    expect(LivewireHttp::redirect($response))->toBe('https://checkout.paystack.com/'.$reference);
    [$payment] = invoicePayments($this->acme, FIRST_INVOICE);
    expect($payment->only(['paystack_reference', 'status', 'initiated_by_user_id']))->toEqual(['paystack_reference' => $reference, 'status' => PlatformPaymentStatus::Pending, 'initiated_by_user_id' => $this->owner->id])
        ->and($payment->amount_kobo->kobo)->toBe(FIRST_INVOICE_KOBO);
    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.paystack.co/transaction/initialize'
        && $request->hasHeader('Authorization', 'Bearer sk_test_goproperty_platform')
        && $request['amount'] === (string) FIRST_INVOICE_KOBO
        && $request['reference'] === $reference
        && $request['email'] === 'ada@adeyemi.ng'
        && $request['callback_url'] === url('/o/acme-homes/settings/subscription/paid'));

    // Paystack's webhook: verified by fetch, the payment confirmed, the invoice paid, the subscription active.
    subscriptionWebhook($reference)->assertOk()->assertJson(['received' => true]);

    expect(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid)
        ->and(invoicePayments($this->acme, FIRST_INVOICE)[0]->only(['status', 'paystack_channel']))->toEqual(['status' => PlatformPaymentStatus::Confirmed, 'paystack_channel' => 'card'])
        ->and(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and(subscriptionOf($this->acme)->past_due_at)->toBeNull();

    // The same event twice more, and the owner comes back from checkout: still one payment.
    subscriptionWebhook($reference)->assertOk();
    subscriptionWebhook($reference)->assertOk();
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/subscription/paid?trxref='.$reference.'&reference='.$reference)
        ->assertRedirect('/o/acme-homes/settings/subscription')
        ->assertSessionHas('subscription_status', ['success', 'Payment received: the invoice is paid. Thank you.']);

    expect(invoicePayments($this->acme, FIRST_INVOICE))->toHaveCount(1)
        ->and(DB::table('webhook_log')->whereNull('organization_id')->orderBy('id')->pluck('outcome')->all())->toBe(['confirmed', 'duplicate', 'duplicate'])
        ->and(AuditEntry::query()->where('log_name', SubscriptionMetering::LOG)->where('event', 'platform_invoice.paid')->sole()->properties['subscription_reactivated'])->toBeTrue()
        ->and(AuditEntry::query()->where('log_name', 'subscriptions')->where('event', 'subscription.active')->count())->toBe(2);   // the trial's end, then the payment
    // The checkout and one verify by fetch: once settled, later calls never ask Paystack again.
    $http->assertSentCount(2);

    // The owners are told once, in the app and by email.
    Notification::assertSentToTimes($this->owner, PlatformPaymentReceived::class, 1);
    Notification::assertNotSentTo($this->admin, PlatformPaymentReceived::class);
    $mail = (new PlatformPaymentReceived($this->acme->id, $payment->id))->toMail($this->owner);
    expect($mail->subject)->toBe('Payment received for GoProperty invoice '.FIRST_INVOICE)
        ->and((string) $mail->render())->toContain('GoProperty received ₦12,900 on 24 November 2026 for invoice '.FIRST_INVOICE.' (16–30 November 2026)')
        ->and((string) $mail->render())->toContain($reference);

    // Settings → Subscription shows it paid, with no Pay button.
    exportPage($this->owner, '/settings/subscription')->assertOk()->assertSee('Paid')->assertDontSee('data-test="pay-'.FIRST_INVOICE.'"', false);
});

it('acceptance: drops and logs a webhook with a forged signature', function () {
    $reference = 'PINV-'.FIRST_INVOICE.'-a1';
    $http = subscriptionPaystack([$reference => ['success', FIRST_INVOICE_KOBO]]);
    payInvoice($this->owner);

    subscriptionWebhook($reference, signature: hash_hmac('sha512', 'another body', 'sk_test_goproperty_platform'))->assertStatus(401);
    subscriptionWebhook($reference, signature: hash_hmac('sha512', '{"event":"charge.success"}', 'sk_test_someone_else'))->assertStatus(401);

    $logs = DB::table('webhook_log')->whereNull('organization_id')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->map(fn (object $log): array => [(bool) $log->signature_valid, $log->outcome, $log->reference])->all())->toBe([[false, 'rejected', $reference], [false, 'rejected', $reference]])
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Open)
        ->and(invoicePayments($this->acme, FIRST_INVOICE)[0]->status)->toBe(PlatformPaymentStatus::Pending);
    // Only the checkout went to Paystack: a forged call is never processed.
    $http->assertSentCount(1);
    Notification::assertNotSentTo($this->owner, PlatformPaymentReceived::class);
});

it('restores a suspended organization once its last overdue invoice is paid, and not before', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:45', 'Africa/Lagos'));
    meterUnits();                                                     // PR-2026-000002, December, due 8 December
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00', 'Africa/Lagos'));
    inOrganization($this->acme, function (): void {
        app(SubscriptionService::class)->markPastDue();
        app(SubscriptionService::class)->startGrace();
        app(SubscriptionService::class)->suspend();
    });
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Suspended);

    $december = invoiceNumbered($this->acme, 'PR-2026-000002');
    subscriptionPaystack([
        'PINV-PR-2026-000002-a1' => ['success', $december->total_kobo->kobo],
        'PINV-'.FIRST_INVOICE.'-a1' => ['success', FIRST_INVOICE_KOBO],
    ]);

    // Paying works while the organization is read-only (it is how it becomes active again).
    expect(LivewireHttp::redirect(payInvoice($this->owner, 'PR-2026-000002')->assertOk()))->toBe('https://checkout.paystack.com/PINV-PR-2026-000002-a1');
    subscriptionWebhook('PINV-PR-2026-000002-a1', amount: $december->total_kobo->kobo)->assertOk();

    // November is still overdue: still suspended.
    expect(invoiceNumbered($this->acme, 'PR-2026-000002')->status)->toBe(InvoiceStatus::Paid)
        ->and(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended)
        ->and($this->acme->fresh()->status)->toBe(OrganizationStatus::Suspended);

    payInvoice($this->owner)->assertOk();
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/subscription/paid?reference=PINV-'.FIRST_INVOICE.'-a1')->assertRedirect();

    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and($this->acme->fresh()->status)->toBe(OrganizationStatus::Active)
        // Active again from 10 December: nothing more to bill until January (December is invoiced already).
        ->and(subscriptionInvoices($this->acme))->toHaveCount(2);
});

it('holds a payment that does not match the invoice, keeps the invoice of a failed one open, and pays again with a new reference', function () {
    subscriptionPaystack(['PINV-'.FIRST_INVOICE.'-a1' => ['failed', FIRST_INVOICE_KOBO], 'PINV-'.FIRST_INVOICE.'-a2' => ['success', FIRST_INVOICE_KOBO - 100]]);

    // The card is declined: Paystack's charge.failed, verified; the payment fails, the invoice stays open.
    payInvoice($this->owner);
    subscriptionWebhook('PINV-'.FIRST_INVOICE.'-a1', event: 'charge.failed')->assertOk();
    expect(invoicePayments($this->acme, FIRST_INVOICE)[0]->status)->toBe(PlatformPaymentStatus::Failed)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Open)
        ->and(DB::table('webhook_log')->whereNull('organization_id')->sole()->outcome)->toBe('not_paid');

    // Paid again (a new reference), but ₦1 short: held for staff, never applied.
    expect(LivewireHttp::redirect(payInvoice($this->owner)))->toBe('https://checkout.paystack.com/PINV-'.FIRST_INVOICE.'-a2');
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/subscription/paid?reference=PINV-'.FIRST_INVOICE.'-a2')
        ->assertSessionHas('subscription_status', ['warning', 'Paystack took a payment that does not match the invoice, so it is not marked paid yet. GoProperty will look into it and contact you.']);

    $held = invoicePayments($this->acme, FIRST_INVOICE)[1];
    expect($held->status)->toBe(PlatformPaymentStatus::Held)
        ->and($held->hold_reason)->toContain('₦12,899 was paid, but ₦12,900 was asked for invoice '.FIRST_INVOICE)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Open)
        ->and(AuditEntry::query()->where('event', 'platform_payment.held')->count())->toBe(1);
    Notification::assertNotSentTo($this->owner, PlatformPaymentReceived::class);
});

it('asks Paystack about a pending payment before starting another, and refuses to pay an invoice already paid', function () {
    subscriptionPaystack(['PINV-'.FIRST_INVOICE.'-a1' => ['success', FIRST_INVOICE_KOBO]]);
    payInvoice($this->owner);

    // The owner paid but never came back, and presses Pay again: the first payment is found, nothing new starts.
    payInvoice($this->owner)->assertOk()->assertSee('Invoice '.FIRST_INVOICE.' is already paid.');
    expect(invoicePayments($this->acme, FIRST_INVOICE))->toHaveCount(1)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid);
});

it('reconciles pending payments hourly when no webhook came, and leaves abandoned checkouts alone', function () {
    $http = subscriptionPaystack(['PINV-'.FIRST_INVOICE.'-a1' => ['success', FIRST_INVOICE_KOBO]]);
    payInvoice($this->owner);

    // Too recent to ask about.
    $this->travel(10)->minutes();
    app()->call([new ReconcilePlatformPayments, 'handle']);
    $http->assertSentCount(1);

    $this->travel(10)->minutes();
    app()->call([new ReconcilePlatformPayments, 'handle']);
    app()->call([new ReconcilePlatformPayments, 'handle']);
    expect(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid);
    $http->assertSentCount(2);

    // An abandoned checkout more than 3 days old is no longer asked about.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:45', 'Africa/Lagos'));
    meterUnits();
    $http = subscriptionPaystack();
    payInvoice($this->owner, 'PR-2026-000002');
    $this->travel(4)->days();
    app()->call([new ReconcilePlatformPayments, 'handle']);
    $http->assertSentCount(1);
    expect(invoicePayments($this->acme, 'PR-2026-000002')[0]->status)->toBe(PlatformPaymentStatus::Pending);

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'saas-paystack-reconcile');
    expect($event)->toBeInstanceOf(CallbackEvent::class)->and($event->expression)->toBe('50 * * * *');
});

it('lets only owners pay: not administrators, not staff while impersonating, and not without GoProperty\'s Paystack', function () {
    subscriptionPaystack();
    exportPage($this->owner, '/settings/subscription')->assertOk()->assertSee('data-test="pay-'.FIRST_INVOICE.'"', false)->assertSee('Pay ₦12,900');
    exportPage($this->admin, '/settings/subscription')->assertOk()->assertSee(FIRST_INVOICE)->assertDontSee('data-test="pay-'.FIRST_INVOICE.'"', false);
    payInvoice($this->admin)->assertForbidden();

    seedStaffRoles();
    exportsFreshRequest();
    enterSupport(startSupport(staffMember(['name' => 'Sola Support', 'email' => 'sola@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');
    $page = asSupport()->get('/o/acme-homes/settings/subscription')->assertOk()->assertDontSee('data-test="pay-'.FIRST_INVOICE.'"', false);
    asSupport();
    LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['pay', [FIRST_INVOICE]]])->assertForbidden();
    expect(invoicePayments($this->acme, FIRST_INVOICE))->toBe([]);
});

it('offers no Pay button without GoProperty\'s Paystack key, and never a live key outside production', function () {
    withoutPaystackKey();
    exportPage($this->owner, '/settings/subscription')->assertOk()->assertDontSee('data-test="pay-'.FIRST_INVOICE.'"', false);
    payInvoice($this->owner)->assertOk()->assertSee('Paying online is not available yet.');

    config(['services.paystack.secret_key' => 'sk_live_goproperty']);
    exportPage($this->owner, '/settings/subscription')->assertOk()->assertDontSee('data-test="pay-'.FIRST_INVOICE.'"', false);
    expect(invoicePayments($this->acme, FIRST_INVOICE))->toBe([]);
});

it('shows GoProperty\'s Paystack webhooks in the console and lets technical admins replay a signed one, audited, never paying twice', function () {
    $reference = 'PINV-'.FIRST_INVOICE.'-a1';
    $http = subscriptionPaystack([$reference => ['success', FIRST_INVOICE_KOBO]]);
    payInvoice($this->owner);
    subscriptionWebhook($reference, signature: 'forged')->assertStatus(401);
    subscriptionWebhook($reference)->assertOk();
    [$forged, $signed] = DB::table('webhook_log')->whereNull('organization_id')->orderBy('id')->pluck('id')->all();

    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $console = '/'.config('app.admin_path');

    foreach (['content-manager', 'analyst', 'support-agent'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(ucfirst(str_replace('-', '', $role)), [$role]), 'staff');
        $this->get($console.'/paystack-webhooks')->assertForbidden();
    }

    // Finance sees the calls but cannot replay them.
    $this->flushSession();
    $this->actingAs(staffNamed('Funmi', ['finance-admin']), 'staff');
    $this->get($console.'/paystack-webhooks')->assertOk()->assertSee($reference);
    Livewire::test(ListPaystackWebhooks::class)->assertCanSeeTableRecords([$forged, $signed])->assertTableActionHidden('replay', $signed);

    // A technical admin replays the signed call: processed again, still one payment, audited; the forged one never.
    $this->flushSession();
    $tech = staffNamed('Tunde', ['technical-admin']);
    $this->actingAs($tech, 'staff');
    Livewire::test(ListPaystackWebhooks::class)
        ->assertTableActionHidden('replay', $forged)
        ->callTableAction('replay', $signed);

    expect(invoicePayments($this->acme, FIRST_INVOICE))->toHaveCount(1)
        ->and(DB::table('webhook_log')->where('id', $signed)->value('outcome'))->toBe('duplicate')
        ->and(AuditEntry::query()->where('log_name', WebhookReplay::LOG)->where('event', 'webhook.replayed')->sole()->causer_id)->toBe($tech->id);
    expect(fn () => app(WebhookReplay::class)->replay($tech, PaystackWebhookDelivery::query()->findOrFail($forged)))->toThrow(AuthorizationException::class);
    $http->assertSentCount(2);
});
