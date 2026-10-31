<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Compliance\TransactionTiers;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\OrganizationTierOverride;
use App\Platform\Compliance\TierOverrides;
use App\Platform\Compliance\TierSettingsRefused;
use App\Platform\Customers\Customer;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Platform\Filament\Resources\Customers\RelationManagers\HistoryRelationManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * P06-08: custom Transaction Tier limits for one organization
 * (ONBOARDING_COMPLIANCE.md § 5.2 "Per-organization override",
 * PLATFORM_CONSOLE.md § 1A: compliance, reason and expiry required, no
 * approval; docs/plans/P06-subtasks.md defaults 79–80). Set / End tier limits
 * on the organization's console page with `tiers.override`, through
 * App\Platform\Compliance\TierOverrides, audited on the organization's
 * History; App\Compliance\TransactionTiers applies them instead of the tier's.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');

    $this->kemi = staffNamed('Kemi', ['compliance-officer']);
    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'transaction_tier' => TransactionTier::Two]);
    $this->beta = Organization::factory()->create(['name' => 'Beta Lets', 'transaction_tier' => TransactionTier::Two]);
});

function tierLimitsOf(Organization $organization): array
{
    $limits = app(TransactionTiers::class)->forOrganization($organization);

    return [$limits->annualLimit()?->format(), $limits->singlePaymentLimit()?->format()];
}

it('acceptance: compliance sets a custom single-payment limit for one organization until its end day', function () {
    $this->actingAs($this->kemi, 'staff');

    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->assertActionVisible('setTierLimits')
        ->assertActionHidden('endTierLimits')
        ->callAction('setTierLimits', data: [
            'annual' => null,
            'single' => 120_000_000,
            'until' => '2027-03-01',
            'reason' => 'One commercial tenant pays ₦110m a year',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Custom limits set for Acme Homes until 1 Mar 2027.');

    // Above Tier 2's ₦50m per payment for Acme only; its yearly limit stays the tier's.
    expect(tierLimitsOf($this->acme))->toBe(['₦500,000,000', '₦120,000,000'])
        ->and(tierLimitsOf($this->beta))->toBe(['₦500,000,000', '₦50,000,000']);

    $override = inOrganization($this->acme, fn () => OrganizationTierOverride::query()->sole());
    expect($override->set_by_staff_id)->toBe($this->kemi->id)
        ->and($override->expires_at?->setTimezone('Africa/Lagos')->toDateTimeString())->toBe('2027-03-01 00:00:00');

    $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()
        ->assertSee('₦120,000,000 (custom)')
        ->assertSee('1 Mar 2027')
        ->assertSee('One commercial tenant pays ₦110m a year');

    // Audited on the organization's History, with who and why.
    $entry = AuditEntry::query()->where('event', 'tier_override.set')->sole();
    expect($entry->causer_id)->toBe($this->kemi->id)
        ->and($entry->log_name)->toBe('compliance')
        ->and($entry->reason)->toBe('One commercial tenant pays ₦110m a year')
        ->and($entry->properties['old'])->toBeNull()
        ->and($entry->properties['attributes']['single_payment_limit_kobo'])->toBe(Money::naira(120_000_000)->kobo);
    Livewire::test(HistoryRelationManager::class, ['ownerRecord' => Customer::query()->findOrFail($this->acme->id), 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$entry]);

    // It stops at the start of its end day; the tier's limits apply again.
    $this->travelTo(CarbonImmutable::parse('2027-02-28 23:59', 'Africa/Lagos'));
    expect(tierLimitsOf($this->acme)[1])->toBe('₦120,000,000');
    $this->travelTo(CarbonImmutable::parse('2027-03-01 00:00', 'Africa/Lagos'));
    expect(tierLimitsOf($this->acme)[1])->toBe('₦50,000,000');
});

it('replaces custom limits and ends them now, keeping the row', function () {
    $overrides = app(TierOverrides::class);
    $overrides->set($this->kemi, $this->acme, Money::naira(800_000_000)->kobo, null, 'Large estate', '2027-01-01');
    $overrides->set($this->kemi, $this->acme, Money::naira(900_000_000)->kobo, Money::naira(60_000_000)->kobo, 'Second estate signed', '2027-06-01');

    expect(tierLimitsOf($this->acme))->toBe(['₦900,000,000', '₦60,000,000'])
        ->and(AuditEntry::query()->where('event', 'tier_override.set')->latest('id')->first()?->properties['old']['annual_volume_limit_kobo'])->toBe(Money::naira(800_000_000)->kobo);

    $this->actingAs($this->kemi, 'staff');
    Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
        ->assertActionVisible('endTierLimits')
        ->callAction('endTierLimits', data: ['reason' => 'Estate sold'])
        ->assertNotified('The tier\'s limits apply again to Acme Homes.');

    expect(tierLimitsOf($this->acme))->toBe(['₦500,000,000', '₦50,000,000'])
        ->and(inOrganization($this->acme, fn () => OrganizationTierOverride::query()->count()))->toBe(1)
        ->and(AuditEntry::query()->where('event', 'tier_override.ended')->sole()->reason)->toBe('Estate sold')
        ->and(fn () => $overrides->end($this->kemi, $this->acme))->toThrow(TierSettingsRefused::class, 'no custom Transaction Tier limits in force');
});

it('refuses custom limits that break the rules', function (?int $annual, ?int $single, string $until, string $reason, string $message) {
    expect(fn () => app(TierOverrides::class)->set($this->kemi, $this->acme, $annual, $single, $reason, $until))
        ->toThrow(TierSettingsRefused::class, $message);
})->with([
    'no limit' => [null, null, '2027-01-01', 'A reason', 'Give a yearly limit, a single-payment limit or both'],
    'single above yearly' => [Money::naira(10_000_000)->kobo, Money::naira(20_000_000)->kobo, '2027-01-01', 'A reason', 'cannot be above the yearly limit'],
    'single above the tier\'s yearly' => [null, Money::naira(600_000_000)->kobo, '2027-01-01', 'A reason', 'cannot be above the yearly limit (₦500,000,000)'],
    'ends today' => [Money::naira(600_000_000)->kobo, null, '2026-11-10', 'A reason', 'from tomorrow at the earliest'],
    'over a year' => [Money::naira(600_000_000)->kobo, null, '2027-11-11', 'A reason', 'at most a year ahead'],
    'no reason' => [Money::naira(600_000_000)->kobo, null, '2027-01-01', ' ', 'Give a reason'],
    'zero' => [0, null, '2027-01-01', 'A reason', 'between ₦1 and'],
]);

it('lets only tiers.override set or end custom limits', function () {
    foreach (['operations-manager', 'finance-admin', 'support-agent'] as $role) {
        $staff = staffNamed('Role'.str_replace('-', '', $role), [$role]);
        $this->actingAs($staff, 'staff');

        expect(Gate::forUser($staff)->allows('overrideTierLimits', Customer::query()->findOrFail($this->acme->id)))->toBeFalse()
            ->and(fn () => app(TierOverrides::class)->set($staff, $this->acme, Money::naira(600_000_000)->kobo, null, 'A reason', '2027-01-01'))
            ->toThrow(TierSettingsRefused::class, 'You do not have permission');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionHidden('setTierLimits')
            ->assertActionHidden('endTierLimits');
    }

    expect(Gate::forUser(staffNamed('Bola', ['super-admin']))->allows('overrideTierLimits', Customer::query()->findOrFail($this->acme->id)))->toBeTrue()
        ->and(inOrganization($this->acme, fn () => OrganizationTierOverride::query()->count()))->toBe(0);
});
