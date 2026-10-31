<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invitation;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\SmsCreditEntry;
use App\Models\User;
use App\Sms\Credits\CreditReason;
use App\Sms\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\SaasBilling\Enums\DunningStep;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Jobs\RunDunning;
use Modules\SaasBilling\Models\DunningNotice;
use Modules\SaasBilling\Notifications\PlatformPaymentReceived;
use Modules\SaasBilling\Notifications\SubscriptionOverdue;
use Modules\SaasBilling\Notifications\SubscriptionPaymentFailed;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Support/members.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';
require_once __DIR__.'/../../../Payments/tests/Feature/helpers.php';
require_once __DIR__.'/../../../Tickets/tests/Feature/helpers.php';
require_once __DIR__.'/metering-helpers.php';
require_once __DIR__.'/payment-helpers.php';

/*
 * P06-05: the dunning ladder and suspension gating (BILLING_PAYMENTS.md
 * § 2 and § 6; docs/plans/P06-subtasks.md "P06-05"). Acceptance: "Failed
 * charge → retry ladder → past_due → grace → suspended (write-blocked) →
 * pay → reactivated; suspension blocks every write surface (spot-check list
 * of 10 actions in tests)". No card is kept (the product owner's decision
 * of 2026-10-30), so a failed payment is not retried: the owners are told
 * once with a Pay now link (decided 2026-10-31) and the ladder follows the
 * invoice's due date. GoProperty pays for its own suspension SMS
 * (decided 2026-10-31).
 *
 * The world: Acme on Starter with 30 units; its trial ends on 16 November,
 * invoice PR-2026-000001 for 16–30 November: ₦12,900, due 23 November.
 * Unpaid, it is past due on 24 November, in grace on 1 December and
 * suspended on 8 December. December's invoice (₦25,800) is due 8 December.
 */

const DECEMBER_INVOICE = 'PR-2026-000002';

const DECEMBER_INVOICE_KOBO = 2_580_000;

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active, 'state' => 'Lagos']);
    $this->owner = joinAs(tap(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@adeyemi.ng', 'phone' => '+2348020000101']), fn (User $user) => $user->forceFill(['phone_verified_at' => now()])->save()), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bisi Admin', 'phone' => '+2348020000102']), $this->acme, OrganizationRole::Admin);
    withUnits($this->acme, 30);
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();
});

afterEach(fn () => exportsFreshRequest());

/** `saas-dunning` as the scheduler runs it, at :55 on the given Lagos day (or now). */
function runDunning(?string $at = null): void
{
    if ($at !== null) {
        test()->travelTo(CarbonImmutable::parse($at, 'Africa/Lagos'));
    }

    app()->call([new RunDunning, 'handle']);
}

/** @return list<string> the organization's dunning notices as "step date" */
function dunningNotices(Organization $organization): array
{
    return inOrganization($organization, fn (): array => DunningNotice::query()->orderBy('id')->get()
        ->map(fn (DunningNotice $notice): string => $notice->step->value.' '.$notice->notice_date->toDateString())->all());
}

function lastDunningNotice(Organization $organization): DunningNotice
{
    return inOrganization($organization, fn (): DunningNotice => DunningNotice::query()->orderByDesc('id')->firstOrFail());
}

/** @return list<string> the subscription's audited moves, oldest first */
function subscriptionMoves(): array
{
    return AuditEntry::query()->where('log_name', 'subscriptions')->where('event', 'like', 'subscription.%')->orderBy('id')->pluck('event')->all();
}

/** The workspace home page as the user's browser loads it. */
function workspaceHome(User $user): TestResponse
{
    exportsFreshRequest();

    return test()->actingAs($user)->get('/o/acme-homes');
}

/** Runs the ladder from the invoice's due date until the organization is suspended (8 December). */
function dunUntilSuspended(): void
{
    runDunning('2026-11-24 00:55');
    runDunning('2026-12-01 00:55');
    runDunning('2026-12-08 00:55');
}

it('acceptance: failed charge → no retry → past_due → grace → suspended (write-blocked) → pay → reactivated', function () {
    $http = subscriptionPaystack([
        'PINV-'.FIRST_INVOICE.'-a1' => ['failed', FIRST_INVOICE_KOBO],
        'PINV-'.FIRST_INVOICE.'-a2' => ['success', FIRST_INVOICE_KOBO],
        'PINV-'.DECEMBER_INVOICE.'-a1' => ['success', DECEMBER_INVOICE_KOBO],
    ]);

    // The owner pays and the card is declined: the payment fails, the invoice stays open, the owners are told once.
    payInvoice($this->owner)->assertOk();
    subscriptionWebhook('PINV-'.FIRST_INVOICE.'-a1', event: 'charge.failed')->assertOk();
    subscriptionWebhook('PINV-'.FIRST_INVOICE.'-a1', event: 'charge.failed')->assertOk();
    [$failed] = invoicePayments($this->acme, FIRST_INVOICE);
    expect($failed->status)->toBe(PlatformPaymentStatus::Failed)
        ->and(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Open);
    Notification::assertSentToTimes($this->owner, SubscriptionPaymentFailed::class, 1);
    Notification::assertNotSentTo($this->admin, SubscriptionPaymentFailed::class);
    $mail = (new SubscriptionPaymentFailed($this->acme->id, $failed->id))->toMail($this->owner);
    expect($mail->subject)->toBe('Your payment for GoProperty invoice '.FIRST_INVOICE.' did not go through')
        ->and((string) $mail->render())->toContain('Pay now')
        ->and((string) $mail->render())->toContain(url('/o/acme-homes/settings/subscription'))
        ->and((string) $mail->render())->toContain('Invoice '.FIRST_INVOICE.' for ₦12,900 is still open, due by 23 November 2026');

    // No card is kept, so nothing is charged again: only the checkout and one verify went to Paystack, whatever the ladder does.
    runDunning('2026-11-17 00:55');
    runDunning('2026-11-23 23:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and(dunningNotices($this->acme))->toBe([]);

    // The day after the due date: past due, the owners told once however often the job runs.
    runDunning('2026-11-24 00:55');
    runDunning('2026-11-24 13:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
        ->and(dunningNotices($this->acme))->toBe(['past_due 2026-11-24']);
    Notification::assertSentToTimes($this->owner, SubscriptionOverdue::class, 1);
    $notice = lastDunningNotice($this->acme);
    expect($notice->only(['overdue_kobo', 'invoice_numbers', 'recipients', 'sms']))->toEqual(['overdue_kobo' => FIRST_INVOICE_KOBO, 'invoice_numbers' => [FIRST_INVOICE], 'recipients' => 1, 'sms' => 0])
        ->and($notice->next_step_on?->toDateString())->toBe('2026-12-08');
    $mail = (new SubscriptionOverdue($this->acme->id, $notice->id))->toMail($this->owner);
    expect($mail->subject)->toBe('Your GoProperty subscription is overdue: ₦12,900 unpaid')
        ->and((string) $mail->render())->toContain('If it is still unpaid on 8 December 2026, Adeyemi Realty Ltd will be suspended');
    workspaceHome($this->owner)->assertOk()->assertSee('Your subscription payment is overdue')->assertSee('If it is still unpaid on 8 December 2026');
    workspaceHome($this->admin)->assertOk()->assertSee('Your subscription payment is overdue');

    // A week later: grace, with a warning each day until suspension (one a day, the job hourly).
    runDunning('2026-11-30 22:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:45', 'Africa/Lagos'));
    meterUnits();                                                      // December's invoice, due 8 December
    foreach (['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06', '2026-12-07'] as $day) {
        runDunning("{$day} 00:55");
        runDunning("{$day} 17:55");
    }
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Grace)
        ->and(array_slice(dunningNotices($this->acme), 1))->toBe(array_map(fn (int $d): string => 'grace_warning 2026-12-0'.$d, range(1, 7)));
    Notification::assertSentToTimes($this->owner, SubscriptionOverdue::class, 8);
    $mail = (new SubscriptionOverdue($this->acme->id, lastDunningNotice($this->acme)->id))->toMail($this->owner);
    expect($mail->subject)->toBe('Adeyemi Realty Ltd will be suspended on 8 December 2026 unless ₦12,900 is paid')
        ->and((string) $mail->render())->toContain('In 1 day, on 8 December 2026');
    workspaceHome($this->owner)->assertSee('Final days before suspension: your subscription is unpaid');

    // 14 days past due: suspended. Read-only from the next request; email, in-app and an SMS paid by GoProperty.
    runDunning('2026-12-08 00:55');
    runDunning('2026-12-08 01:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended)
        ->and($this->acme->fresh()->status)->toBe(OrganizationStatus::Suspended)
        ->and(dunningNotices($this->acme))->toContain('suspended 2026-12-08')
        ->and(lastDunningNotice($this->acme)->only(['recipients', 'sms']))->toEqual(['recipients' => 1, 'sms' => 1])
        ->and(lastDunningNotice($this->acme)->next_step_on?->toDateString())->toBe('2027-03-08');
    Notification::assertSentToTimes($this->owner, SubscriptionOverdue::class, 9);
    $sms = inOrganization($this->acme, fn (): NotificationDelivery => NotificationDelivery::query()->sole());
    expect($sms->only(['notification_type', 'recipient_contact', 'recipient_user_id', 'paid_by_platform', 'status']))->toEqual([
        'notification_type' => 'billing.suspended', 'recipient_contact' => '+2348020000101', 'recipient_user_id' => $this->owner->id, 'paid_by_platform' => true, 'status' => DeliveryStatus::Delivered,
    ])->and($sms->body)->toBe('GoProperty: Adeyemi Realty Ltd is suspended, NGN 12,900 unpaid. An owner can pay in Settings > Subscription to restore it.')
        ->and(inOrganization($this->acme, fn (): int => SmsCreditEntry::query()->where('reason', CreditReason::Usage)->count()))->toBe(0);

    $profile = settingsPage($this->owner, 'profile');
    exportsFreshRequest();
    LivewireHttp::update(test()->actingAs($this->owner), $profile, ['name' => 'Adeyemi Realty Limited'], [['save']])->assertForbidden();
    expect($this->acme->fresh()->name)->toBe('Adeyemi Realty Ltd');
    workspaceHome($this->owner)->assertOk()->assertSee('This organization is suspended')->assertSee('Your subscription is unpaid, so this organization is suspended')->assertSee('If they are still unpaid on 8 March 2027');

    // Paying is allowed while read-only. November paid: December is overdue too, so still suspended.
    $this->travelTo(CarbonImmutable::parse('2026-12-09 09:00', 'Africa/Lagos'));
    payInvoice($this->owner)->assertOk();
    subscriptionWebhook('PINV-'.FIRST_INVOICE.'-a2')->assertOk();
    expect(invoiceNumbered($this->acme, FIRST_INVOICE)->status)->toBe(InvoiceStatus::Paid)
        ->and(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended);
    runDunning('2026-12-09 09:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended);

    // December paid too: active again at once, writes work again, and the ladder has nothing more to do.
    payInvoice($this->owner, DECEMBER_INVOICE)->assertOk();
    subscriptionWebhook('PINV-'.DECEMBER_INVOICE.'-a1', amount: DECEMBER_INVOICE_KOBO)->assertOk();
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and($this->acme->fresh()->status)->toBe(OrganizationStatus::Active);
    Notification::assertSentToTimes($this->owner, PlatformPaymentReceived::class, 2);

    $profile = settingsPage($this->owner, 'profile');
    exportsFreshRequest();
    LivewireHttp::update(test()->actingAs($this->owner), $profile, ['name' => 'Adeyemi Realty Limited', 'address' => '1 Marina, Lagos Island', 'state' => 'Ogun', 'lga' => 'Abeokuta South', 'cacNumber' => 'rc 7654321'], [['save']])->assertOk();
    expect($this->acme->fresh()->name)->toBe('Adeyemi Realty Limited');
    workspaceHome($this->owner)->assertDontSee('This organization is suspended')->assertDontSee('Your subscription payment is overdue');

    runDunning('2026-12-10 00:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and(subscriptionMoves())->toBe(['subscription.started', 'subscription.active', 'subscription.past_due', 'subscription.grace', 'subscription.suspended', 'subscription.active']);
    // Checkouts and verifies only: three checkouts the owner started, never a charge GoProperty started by itself.
    $http->assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), '/transaction/initialize') && ! str_contains($request->url(), '/transaction/verify/'));
    expect($http->recorded(fn (Request $request): bool => str_contains($request->url(), '/transaction/initialize')))->toHaveCount(3);
});

it('acceptance: suspension blocks every write surface (10 actions), and paying opens them again', function () {
    Storage::fake('media');
    Queue::fake([RenderInvoice::class, RenderReceipt::class, RenderLeaseContract::class]);
    subscriptionPaystack([
        'PINV-'.FIRST_INVOICE.'-a1' => ['success', FIRST_INVOICE_KOBO],
        'PINV-'.DECEMBER_INVOICE.'-a1' => ['success', DECEMBER_INVOICE_KOBO],
    ]);

    // The world, while active: a building, units, tenants, a draft lease and a rented flat with its rent bill.
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    [$flat1, $flat2, $flat3, $flat4] = array_map(fn (int $n) => unitIn($this->acme, $palm, ['unit_label' => "Flat {$n}"]), [1, 2, 3, 4]);
    [$kemi, $tunde, $sade] = [tenantIn($this->acme), tenantIn($this->acme), tenantIn($this->acme)];
    $draft = draftLease($this->acme, $this->owner, $flat1, $kemi);
    activeLease($this->acme, $this->owner, $flat2, $tunde, ['startDate' => '2026-11-16', 'endDate' => '2027-11-15']);
    runBillGeneration();
    $bill = billsOf($this->acme)->sole();

    // The pages, as the owner's browser loaded them before the suspension.
    leasesFreshRequest();
    $pages = [
        'add a building' => [buildingsSnapshot($this->owner, '/create'), buildingForm(['name' => 'Cedar Gardens'])],
        'add a unit' => [(function () {
            leasesFreshRequest();

            return unitsSnapshot($this->owner, 'palm-court', '/create');
        })(), unitForm(['unitLabel' => 'Flat 9'])],
        'add a tenant' => [tenantsSnapshot($this->owner, '/create'), tenantForm()],
        'draft a lease' => [leasesSnapshot($this->owner, "/create?unit={$flat3->id}&tenant={$sade->id}"), leaseInput()],
        'activate a lease' => [leasesSnapshot($this->owner, "/{$draft->id}"), [], 'activate'],
        'record a payment' => [paymentsSnapshot($this->owner, '/create'), paymentInput($kemi, '5,000')],
        'void a bill' => [billsSnapshot($this->owner, "/{$bill->id}"), ['reason' => 'Entered for the wrong tenant'], 'void'],
        'raise a ticket' => [ticketsSnapshot($this->owner, "/create?unit={$flat4->id}"), ticketInput()],
    ];
    $written = fn (): array => [
        'buildings' => buildingsOf($this->acme)->count(),
        'units' => unitsOf($this->acme)->count(),
        'tenants' => tenantsOf($this->acme)->count(),
        'leases' => leasesOf($this->acme)->count(),
        'draft' => storedLease($this->acme, $draft)->status,
        'payments' => paymentsOf($this->acme)->count(),
        'bill' => storedBill($this->acme, $bill)->status,
        'tickets' => ticketsOf($this->acme)->count(),
        'invitations' => inOrganization($this->acme, fn (): int => Invitation::query()->count()),
        'name' => $this->acme->fresh()->name,
    ];
    $before = $written();

    $press = function (string $action, array $page): TestResponse {
        leasesFreshRequest();
        test()->actingAs($this->owner);

        return LivewireHttp::update(test(), $page[0], $page[1], [[$page[2] ?? 'save']]);
    };
    $invite = fn (): TestResponse => membersCall($this->owner, [['invite']], ['email' => 'new@adeyemi.ng', 'roles' => ['agent']]);
    $saveProfile = function (): TestResponse {
        $snapshot = settingsPage($this->owner, 'profile');
        leasesFreshRequest();

        return LivewireHttp::update(test()->actingAs($this->owner), $snapshot, ['name' => 'Acme Homes Limited', 'address' => '1 Marina, Lagos Island', 'state' => 'Ogun', 'lga' => 'Abeokuta South', 'cacNumber' => 'rc 7654321'], [['save']]);
    };

    // Unpaid: the ladder suspends the organization on 8 December.
    dunUntilSuspended();
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Suspended);

    // Every one of the ten is refused, and nothing is written.
    foreach ($pages as $action => $page) {
        $press($action, $page)->assertForbidden();
    }
    $invite()->assertForbidden();
    $saveProfile()->assertForbidden();
    expect($written())->toEqual($before);

    // Paying is the one thing that works: both invoices paid, the organization is active again.
    $this->travelTo(CarbonImmutable::parse('2026-12-09 09:00', 'Africa/Lagos'));
    payInvoice($this->owner)->assertOk();
    subscriptionWebhook('PINV-'.FIRST_INVOICE.'-a1')->assertOk();
    payInvoice($this->owner, DECEMBER_INVOICE)->assertOk();
    subscriptionWebhook('PINV-'.DECEMBER_INVOICE.'-a1', amount: DECEMBER_INVOICE_KOBO)->assertOk();
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Active);

    // The same ten, from the same pages, now go through.
    foreach ($pages as $action => $page) {
        $press($action, $page)->assertOk();
    }
    $invite()->assertOk()->assertSee('Invitation sent to');
    $saveProfile()->assertOk();

    expect($written())->toEqual([
        'buildings' => $before['buildings'] + 1,
        'units' => $before['units'] + 1,
        'tenants' => $before['tenants'] + 1,
        'leases' => $before['leases'] + 1,
        'draft' => LeaseStatus::Active,
        'payments' => $before['payments'] + 1,
        'bill' => BillStatus::Void,
        'tickets' => $before['tickets'] + 1,
        'invitations' => $before['invitations'] + 1,
        'name' => 'Acme Homes Limited',
    ]);
});

it('reminds a suspended organization every 14 days, by email and SMS, and cancels it after 90 days', function () {
    dunUntilSuspended();
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended);

    // Hourly runs between reminders send nothing.
    runDunning('2026-12-21 23:55');
    runDunning('2026-12-22 00:55');                                     // 14 days
    runDunning('2026-12-22 05:55');
    // The job was down from 1 to 18 January: the reminder of 5 January is not sent late; 19 January's goes on time.
    runDunning('2027-01-19 00:55');
    runDunning('2027-01-19 08:55');
    expect(array_values(array_filter(dunningNotices($this->acme), fn (string $n): bool => str_starts_with($n, 'suspension_reminder'))))
        ->toBe(['suspension_reminder 2026-12-22', 'suspension_reminder 2027-01-19']);

    $sms = inOrganization($this->acme, fn () => NotificationDelivery::query()->orderBy('id')->get());
    expect($sms->pluck('notification_type')->all())->toBe(['billing.suspended', 'billing.suspension_reminder', 'billing.suspension_reminder'])
        ->and($sms->every(fn (NotificationDelivery $delivery): bool => $delivery->paid_by_platform))->toBeTrue()
        ->and($sms->last()->body)->toBe('GoProperty: Adeyemi Realty Ltd is still suspended, NGN 12,900 unpaid. Pay in Settings > Subscription by 8 Mar 2027.');
    $mail = (new SubscriptionOverdue($this->acme->id, lastDunningNotice($this->acme)->id))->toMail($this->owner);
    expect($mail->subject)->toBe('Reminder: Adeyemi Realty Ltd is still suspended, ₦12,900 unpaid');

    // 89 days: still suspended. 90 days: cancelled, the organization closed, its data kept; nothing more after.
    runDunning('2027-03-07 23:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended);
    runDunning('2027-03-08 00:55');
    runDunning('2027-03-08 01:55');
    runDunning('2027-03-22 00:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(subscriptionOf($this->acme)->ended_at)->not->toBeNull()
        ->and($this->acme->fresh()->status)->toBe(OrganizationStatus::Cancelled)
        ->and(last(dunningNotices($this->acme)))->toBe('cancelled 2027-03-08')
        ->and(lastDunningNotice($this->acme)->sms)->toBe(0)
        ->and(subscriptionInvoices($this->acme))->toHaveCount(1);           // never billed while suspended
    $mail = (new SubscriptionOverdue($this->acme->id, lastDunningNotice($this->acme)->id))->toMail($this->owner);
    expect($mail->subject)->toBe("Adeyemi Realty Ltd's GoProperty subscription is cancelled");
    workspaceHome($this->owner)->assertOk()->assertSee('This organization is closed');
});

it('counts each step from the one before, so an outage never shortens the grace period', function () {
    // The job first runs on 10 December, 17 days after the due date.
    runDunning('2026-12-10 00:55');
    runDunning('2026-12-10 01:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);

    runDunning('2026-12-16 23:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    runDunning('2026-12-17 00:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Grace);
    runDunning('2026-12-23 23:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Grace);
    runDunning('2026-12-24 00:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Suspended)
        ->and(dunningNotices($this->acme))->toBe([
            'past_due 2026-12-10',
            'grace_warning 2026-12-17',
            'grace_warning 2026-12-23',
            'suspended 2026-12-24',
        ]);
});

it('never duns a platform-covered organization, and makes a lapsed subscription with nothing overdue active again', function () {
    // Covered after its invoice was issued: the invoice stays as it is, the subscription is never moved.
    $this->acme->forceFill(['platform_covered' => true])->save();
    runDunning('2026-11-24 00:55');
    runDunning('2026-12-08 00:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and(dunningNotices($this->acme))->toBe([]);

    // Not covered: past due. The invoice is then settled without reactivating (two payments at once each saw the other open).
    $this->acme->forceFill(['platform_covered' => false])->save();
    runDunning('2026-12-08 01:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue);
    inOrganization($this->acme, fn () => invoiceNumbered($this->acme, FIRST_INVOICE)->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => now()])->save());

    runDunning('2026-12-08 02:55');
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and(AuditEntry::query()->where('event', 'subscription.active')->latest('id')->value('description'))->toBe('Nothing is overdue any more');
});

it('sends the suspension SMS only to owners with a verified phone, and never takes an SMS credit for it', function () {
    $second = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => null, 'phone' => '+2348020000103']), $this->acme);

    dunUntilSuspended();

    // Both owners are told in the app (Chidi has no email address); only Ada's phone is verified.
    Notification::assertSentToTimes($second, SubscriptionOverdue::class, 3);   // past due, grace, suspended
    expect(lastDunningNotice($this->acme)->only(['recipients', 'sms']))->toEqual(['recipients' => 2, 'sms' => 1])
        ->and(inOrganization($this->acme, fn () => NotificationDelivery::query()->pluck('recipient_contact')->all()))->toBe(['+2348020000101'])
        ->and((new SubscriptionOverdue($this->acme->id, lastDunningNotice($this->acme)->id))->via($second))->toBe(['database'])
        ->and(inOrganization($this->acme, fn (): int => SmsCreditEntry::query()->where('reason', CreditReason::Usage)->count()))->toBe(0);
});

it('runs hourly at :55 on the billing queue', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'saas-dunning');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('55 * * * *')
        ->and((new RunDunning)->queue)->toBe('billing');

    // Run by the scheduler, it does the day's step.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 00:55', 'Africa/Lagos'));
    $event->run(app());
    expect(subscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
        ->and(dunningNotices($this->acme))->toBe(['past_due 2026-11-24'])
        ->and(DunningStep::PastDue->template())->toBe('billing.past_due');
});
