<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Platform\Customers\CustomerSubscriptions;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Jobs\RunDunning;
use Modules\SaasBilling\Notifications\PlatformInvoiceIssued;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/metering-helpers.php';

/*
 * P06-07a with P06-02's metering (docs/plans/P06-subtasks.md default 52;
 * BILLING_PAYMENTS.md § 2, § 3): staff reactivate a cancelled subscription
 * from the console, and SaasBilling invoices a period starting that day,
 * charged by days — the months it was cancelled are never billed — and the
 * organization the subscription closed reopens. Metering itself is
 * unchanged; this proves the console's move reaches it.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    withUnits($this->acme, 30);
    $this->operator = staffNamed('Tunde', ['operations-manager']);
});

it('acceptance: reactivating a cancelled subscription invoices a period from that day, charged by days, and reopens the organization', function () {
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:00', 'Africa/Lagos'));
    endTrials();
    expect(subscriptionInvoices($this->acme))->toHaveCount(1);

    // Cancelled by staff on 20 November: no invoice while cancelled.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Africa/Lagos'));
    app(CustomerSubscriptions::class)->cancel($this->operator, $this->acme, 'Customer asked to stop');
    expect($this->acme->fresh()?->status)->toBe(OrganizationStatus::Cancelled);
    foreach (['2026-12-01 01:00', '2027-01-01 01:00', '2027-01-15 08:00'] as $moment) {
        $this->travelTo(CarbonImmutable::parse($moment, 'Africa/Lagos'));
        meterUnits();
    }
    expect(subscriptionInvoices($this->acme))->toHaveCount(1);

    // Reactivated from the console on 15 January.
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00', 'Africa/Lagos'));
    Notification::fake();
    $this->actingAs($this->operator, 'staff');
    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->callAction('reactivateSubscription', data: ['reason' => 'Customer is back'])
        ->assertHasNoActionErrors();

    $acme = $this->acme->fresh();
    $subscription = inOrganization($this->acme, fn (): Subscription => Subscription::query()->sole());
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($acme?->status)->toBe(OrganizationStatus::Active);

    [$november, $january] = subscriptionInvoices($this->acme);
    expect(subscriptionInvoices($this->acme))->toHaveCount(2)
        ->and($november->period_start?->toDateString())->toBe('2026-11-16')
        ->and($january->period_start?->toDateString())->toBe('2027-01-15')
        ->and($january->period_end?->toDateString())->toBe('2027-01-31')
        ->and([$january->days_billed, $january->days_in_month])->toBe([17, 31])
        ->and($january->units_billed)->toBe(30)
        ->and($january->subtotal_kobo->kobo)->toBe(1_316_129)                 // ₦24,000 a month × 17/31, to the kobo
        ->and($january->status)->toBe(InvoiceStatus::Open)
        ->and($january->due_date?->toDateString())->toBe('2027-01-22')
        ->and($subscription->current_period_start?->toDateString())->toBe('2027-01-15')
        ->and($subscription->current_period_end?->toDateString())->toBe('2027-01-31');
    Notification::assertSentTo($this->owner, PlatformInvoiceIssued::class);

    // Once only, however often metering runs; then 1 February as usual.
    meterUnits();
    expect(subscriptionInvoices($this->acme))->toHaveCount(2);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 01:00', 'Africa/Lagos'));
    meterUnits();
    expect(subscriptionInvoices($this->acme)[2]->period_start?->toDateString())->toBe('2027-02-01');
});

it('keeps the paid period when reactivated within it, and the dunning ladder follows what is unpaid', function () {
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:00', 'Africa/Lagos'));
    endTrials();
    app(CustomerSubscriptions::class)->cancel($this->operator, $this->acme, 'Customer asked to stop');

    // Back within November: that period is invoiced already, nothing new until 1 December.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 11:00', 'Africa/Lagos'));
    app(CustomerSubscriptions::class)->reactivate($this->operator, $this->acme, 'Changed their mind');
    expect(subscriptionInvoices($this->acme))->toHaveCount(1);

    // November's invoice is still open: past its due date the ladder makes the subscription past due.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Africa/Lagos'));
    app()->call([new RunDunning, 'handle']);
    expect(inOrganization($this->acme, fn (): SubscriptionStatus => Subscription::query()->sole()->status))->toBe(SubscriptionStatus::PastDue);
});
