<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\SmsCreditBalance;
use App\Models\SmsCreditEntry;
use App\Platform\Filament\Pages\Settings\SmsCreditPrices;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Settings\SettingsStore;
use App\Sms\Credits\CreditReason;
use App\Sms\Credits\SmsCredits;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\SaasBilling\Contracts\SmsCreditCheckout;

/*
 * P05-03 in the console (PLATFORM_CONSOLE.md § 1 "Plans, prices, included
 * units, features, SMS credits": finance-admin, `plans.manage`): the price
 * per credit and the bundles, and on a customer's page its balance, a
 * manual adjustment with a reason and a custom monthly allowance, audited.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');
    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes', 'signup_plan_key' => 'starter']);
    inOrganization($this->acme, fn () => app(SmsCredits::class)->refreshAllowance());
});

function acmeBalance(Organization $organization): SmsCreditBalance
{
    return inOrganization($organization, fn (): SmsCreditBalance => SmsCreditBalance::query()->sole());
}

it('lets finance-admin adjust a customer\'s credits with a reason, shown in its ledger and audited', function () {
    $finance = staffNamed('Funmi', ['finance-admin']);
    $this->actingAs($finance, 'staff');

    $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()
        ->assertSeeInOrder(['SMS credits', 'Balance', '100', 'This month\'s allowance left', '100 of 100', 'Monthly allowance', '100 (plan)']);

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->assertActionVisible('adjustSmsCredits')
        ->callAction('adjustSmsCredits', data: ['credits' => 250, 'reason' => 'Goodwill after the GoText outage'])
        ->assertHasNoActionErrors()
        ->assertNotified('SMS credits adjusted. The balance is now 350.')
        ->callAction('adjustSmsCredits', data: ['credits' => -1_000, 'reason' => 'Too many'])
        ->assertNotified('The organization has only 350 credits: an adjustment cannot take it below zero.');

    expect(acmeBalance($this->acme)->only(['allowance_credits', 'purchased_credits']))->toBe(['allowance_credits' => 100, 'purchased_credits' => 250]);
    $entry = inOrganization($this->acme, fn (): SmsCreditEntry => SmsCreditEntry::query()->where('reason', CreditReason::Adjustment)->sole());
    expect($entry->only(['credits', 'note', 'platform_staff_id']))->toBe(['credits' => 250, 'note' => 'Goodwill after the GoText outage', 'platform_staff_id' => $finance->id]);

    $audit = AuditEntry::query()->where('event', 'sms_credits.adjusted')->sole();
    expect($audit->causer_type)->toBe((new PlatformStaff)->getMorphClass())
        ->and($audit->causer_id)->toBe($finance->id)
        ->and($audit->subject_id)->toBe($this->acme->id);
});

it('sets a custom monthly allowance: the difference at once, the plan\'s again when cleared', function () {
    $finance = staffNamed('Funmi', ['finance-admin']);
    $this->actingAs($finance, 'staff');

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->callAction('smsAllowance', data: ['allowance' => 5_000])
        ->assertHasNoActionErrors()
        ->assertNotified('The monthly SMS allowance is now 5,000.');

    expect(acmeBalance($this->acme)->only(['allowance_credits', 'allowance_granted']))->toBe(['allowance_credits' => 5_000, 'allowance_granted' => 5_000])
        ->and(app(SettingsStore::class)->get('sms_credits.monthly_allowance', $this->acme))->toBe(5_000)
        ->and(AuditEntry::query()->where('event', 'sms_credits.allowance_set')->sole()->causer_id)->toBe($finance->id);

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->callAction('smsAllowance', data: ['allowance' => null])
        ->assertNotified('The plan\'s SMS allowance applies again.');

    // A smaller allowance waits for next month.
    expect(acmeBalance($this->acme)->allowance_granted)->toBe(5_000)
        ->and(app(SettingsStore::class)->get('sms_credits.monthly_allowance', $this->acme))->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00', 'Africa/Lagos'));
    inOrganization($this->acme, fn () => app(SmsCredits::class)->refreshAllowance());
    expect(acmeBalance($this->acme)->only(['allowance_credits', 'allowance_granted']))->toBe(['allowance_credits' => 100, 'allowance_granted' => 100]);
});

it('keeps SMS credit changes from staff without plans.manage', function (string $role) {
    $this->actingAs(staffNamed('Sade', [$role]), 'staff');

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->assertActionHidden('adjustSmsCredits')
        ->assertActionHidden('smsAllowance');
    $this->get($this->console.'/settings/sms-credits')->assertForbidden();
})->with(['support-agent', 'operations-manager', 'compliance-officer']);

it('lets finance-admin price the credits and the bundles, audited, and refuses nonsense', function () {
    $this->actingAs(staffNamed('Funmi', ['finance-admin']), 'staff');
    $this->get($this->console.'/settings/sms-credits')->assertOk()->assertSee('SMS credit prices');

    Livewire::test(SmsCreditPrices::class)
        ->assertSet('data.price', '6.00')
        ->assertSet('data.bundles', '500, 1000, 2500, 5000')
        ->set('data.price', '5.50')
        ->set('data.bundles', '1000, 3000')
        ->callAction('save')
        ->assertHasNoErrors()
        ->assertNotified('Saved: new purchases use these prices');

    expect(app(SettingsStore::class)->get('sms_credits.price_kobo'))->toBe(550)
        ->and(app(SettingsStore::class)->get('sms_credits.bundles'))->toBe([1_000, 3_000])
        ->and(AuditEntry::query()->where('log_name', 'settings')->where('properties->key', 'sms_credits.price_kobo')->count())->toBe(1);

    Livewire::test(SmsCreditPrices::class)->set('data.price', 'six')->callAction('save')->assertHasErrors(['data.price']);
    Livewire::test(SmsCreditPrices::class)->set('data.bundles', '500, 500')->callAction('save')->assertHasErrors(['data.bundles']);
    Livewire::test(SmsCreditPrices::class)->set('data.bundles', '1, 2, 3, 4, 5, 6, 7')->callAction('save')->assertHasErrors(['data.bundles']);

    // The bundles on sale follow: 1,000 at ₦5.50 is ₦5,500 + ₦412.50 VAT.
    $bundles = inOrganization($this->acme, fn (): array => app(SmsCreditCheckout::class)->bundles());
    expect(array_map(fn ($bundle): array => [$bundle->credits, $bundle->total->kobo], $bundles))->toBe([[1_000, 591_250], [3_000, 1_773_750]]);
});
