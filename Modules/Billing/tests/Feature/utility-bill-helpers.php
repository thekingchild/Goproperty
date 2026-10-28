<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Utilities\UnitUtilitySettings;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

/*
 * Helpers for the utility bill tests (P03-06b). Types, settings and
 * readings come from the utility helpers (made through the real writers),
 * leases from the Leasing helpers (LeaseService), and bills from the
 * scheduled `bills-generate` job, as production makes them.
 */

require_once __DIR__.'/utility-helpers.php';

/** Travel to the day (Lagos) at the job's hour: 00:10 UTC is 01:10 in Lagos. */
function onUtilityBillingDay(string $date): void
{
    test()->travelTo($date.' 00:10:00');
}

/** A monthly lease of the unit for the tenant, activated (₦100,000 a month, from 1 Jan 2027 unless changed). */
function monthlyLease(Organization $organization, User $by, Unit $unit, TenantProfile $tenant, array $changes = []): Lease
{
    return activeLease($organization, $by, $unit, $tenant, $changes + [
        'billingCycle' => 'monthly',
        'startDate' => '2027-01-01',
        'endDate' => '2027-12-31',
        'rent' => '100,000',
        'deposit' => '0',
    ]);
}

/** The organization's utility bills (voided too), oldest first. */
function utilityBillsOf(Organization $organization, ?UnitUtilitySetting $setting = null): Collection
{
    return inOrganization($organization, fn () => Bill::query()->withTrashed()->where('type', 'utility')
        ->when($setting !== null, fn ($query) => $query->where('unit_utility_setting_id', $setting?->id))
        ->orderBy('id')->get());
}

/**
 * The setting's bills as [period start, period end, due date, amount in kobo, tenant id] rows, by period.
 *
 * @return list<array{string, string, string, int, int}>
 */
function utilityBilledPeriods(Organization $organization, UnitUtilitySetting $setting): array
{
    return utilityBillsOf($organization, $setting)->sortBy(fn (Bill $bill) => $bill->period_start?->toDateString())->values()
        ->map(fn (Bill $bill): array => [$bill->period_start?->toDateString(), $bill->period_end?->toDateString(), $bill->due_date->toDateString(), $bill->amount_kobo->kobo, $bill->tenant_profile_id])
        ->all();
}

/** The bill's lines, in order. */
function billLinesOf(Organization $organization, Bill $bill): Collection
{
    return inOrganization($organization, fn () => BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->get());
}

/** Changes a unit's utility through the writer (keeping what is not given). */
function changeUtility(Organization $organization, User $by, UnitUtilitySetting $setting, array $changes): UnitUtilitySetting
{
    $setting = storedSetting($organization, $setting);

    return inOrganization($organization, fn (): UnitUtilitySetting => app(UnitUtilitySettings::class)->update($by, $setting, $changes + [
        'active' => $setting->active,
        'amount' => $setting->amount_kobo === null ? '' : $setting->amount_kobo->toDecimalString(),
        'cycle' => $setting->cycle->value ?? '',
        'effectiveFrom' => $setting->effective_from->toDateString(),
        'startingReading' => $setting->starting_reading?->input() ?? '',
    ]));
}
