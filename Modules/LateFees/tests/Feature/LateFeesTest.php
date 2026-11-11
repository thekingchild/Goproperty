<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\LateFees\Charges\LateFeeSettings;
use Modules\LateFees\Models\LateFee;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../Billing/tests/Feature/helpers.php';

/*
 * PL-02 (docs/plans/late-fees-2026-11-11.md): an agency's late fees on
 * overdue rent. Off until switched on; then ONE fee bill per rent bill: the
 * rate of what is unpaid once the due date is the grace days behind
 * (Lagos), at most the cap; rent only, never twice, never on a paid or
 * void bill.
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    // Yearly leases from 1 Jan 2027 at ₦1,500,000, due 1 Jan (one due on 4 Jan).
    $this->unpaid = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo']));
    $this->part = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $this->paid = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $this->later = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['dueDayOffset' => '3']);
    $this->voided = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    runBillGeneration();

    $this->billOf = fn ($lease): Bill => billsOf($this->acme)->first(fn (Bill $bill): bool => $bill->lease_id === $lease->id && $bill->type === BillType::Rent);
    inOrganization($this->acme, function (): void {
        DB::transaction(function (): void {
            app(BillLedger::class)->apply(($this->billOf)($this->part)->id, Money::naira(500_000));
            app(BillLedger::class)->apply(($this->billOf)($this->paid)->id, Money::naira(1_500_000));
        });
        app(BillService::class)->void($this->owner, ($this->billOf)($this->voided), 'Wrong tenant');
    });
});

afterEach(fn () => billsFreshRequest());

function lateFeesOn(Organization $organization, int $rateBps = 500, int $graceDays = 7, ?int $capKobo = null): void
{
    $settings = app(SettingsStore::class);
    $settings->set(LateFeeSettings::ENABLED, true, $organization);
    $settings->set(LateFeeSettings::RATE_BPS, $rateBps, $organization);
    $settings->set(LateFeeSettings::GRACE_DAYS, $graceDays, $organization);
    $settings->set(LateFeeSettings::CAP_KOBO, $capKobo, $organization);
}

/** Runs `late-fees-charge` (01:20 daily) as the scheduler does: the job, queued on `billing`, runs inline. */
function runLateFees(): void
{
    billingScheduledEvent('late-fees-charge', '20 1 * * *')->run(app());
}

/** @return array<int, Bill> rent bill id => its late fee bill */
function feeBills(Organization $organization): array
{
    return billsOf($organization)->filter(fn (Bill $bill): bool => $bill->type === BillType::LateFee)
        ->mapWithKeys(fn (Bill $bill): array => [(int) inOrganization($organization, fn () => BillItem::query()->where('bill_id', $bill->id)->value('meta'))['rent_bill_id'] => $bill])
        ->all();
}

it('charges nothing until the agency switches late fees on', function () {
    $this->travelTo('2027-01-20 09:00:00');
    runLateFees();

    expect(feeBills($this->acme))->toBe([])
        ->and(inOrganization($this->acme, fn (): int => LateFee::query()->count()))->toBe(0);
});

it('acceptance: charges one fee bill per overdue rent bill once its grace days have passed, on what is unpaid, never twice', function () {
    lateFeesOn($this->acme);

    // Due 1 Jan, 7 days' grace: nothing on 7 Jan.
    $this->travelTo('2027-01-07 09:00:00');
    runOverdueFlagging();
    runLateFees();
    expect(feeBills($this->acme))->toBe([]);

    // 8 Jan: the unpaid and part-paid bills; not the paid or void one, nor the one due on 4 Jan.
    $this->travelTo('2027-01-08 09:00:00');
    runLateFees();
    $fees = feeBills($this->acme);
    $unpaid = ($this->billOf)($this->unpaid);
    $part = ($this->billOf)($this->part);

    expect(array_keys($fees))->toEqualCanonicalizing([$unpaid->id, $part->id])
        ->and($fees[$unpaid->id]->amount_kobo->kobo)->toBe(7_500_000) // 5 % of ₦1,500,000
        ->and($fees[$part->id]->amount_kobo->kobo)->toBe(5_000_000)   // 5 % of the ₦1,000,000 still unpaid
        ->and($fees[$unpaid->id]->only(['tenant_profile_id', 'lease_id', 'property_id', 'unit_id']))->toBe($unpaid->only(['tenant_profile_id', 'lease_id', 'property_id', 'unit_id']))
        ->and($fees[$unpaid->id]->due_date->toDateString())->toBe('2027-01-08')
        ->and($fees[$unpaid->id]->number)->not->toBe($unpaid->number)
        ->and($fees[$unpaid->id]->description)->toBe('Late fee on '.$unpaid->number);

    $line = inOrganization($this->acme, fn (): BillItem => BillItem::query()->where('bill_id', $fees[$part->id]->id)->sole());
    expect($line->label)->toBe('Late fee')
        ->and($line->note)->toBe('5 % of ₦1,000,000 unpaid on '.$part->number.', due 01/01/2027');

    // Again the same day, and the next: nothing new for those; the 4 Jan bill on 11 Jan.
    runLateFees();
    $this->travelTo('2027-01-11 09:00:00');
    runOverdueFlagging();
    runLateFees();

    expect(feeBills($this->acme))->toHaveCount(3)
        ->and(array_key_exists(($this->billOf)($this->later)->id, feeBills($this->acme)))->toBeTrue()
        ->and(inOrganization($this->acme, fn (): int => LateFee::query()->count()))->toBe(3)
        ->and(Activity::query()->where('log_name', 'late_fees')->where('event', 'late_fee.charged')->count())->toBe(3);
});

it('caps the fee, and charges a voided fee\'s rent bill no second time', function () {
    lateFeesOn($this->acme, rateBps: 500, graceDays: 7, capKobo: 6_000_000);
    $this->travelTo('2027-01-08 09:00:00');
    runOverdueFlagging();
    runLateFees();

    $unpaid = ($this->billOf)($this->unpaid);
    $fee = feeBills($this->acme)[$unpaid->id];
    expect($fee->amount_kobo->kobo)->toBe(6_000_000)
        ->and(inOrganization($this->acme, fn (): ?string => BillItem::query()->where('bill_id', $fee->id)->value('note')))->toEndWith('(capped at ₦60,000)');

    // Waived: the fee bill is voided; the rent bill is never charged again.
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $fee, 'Waived: tenant paid by transfer on time'));
    $this->travelTo('2027-01-15 09:00:00');
    runLateFees();
    $feesFor = fn (int $rentBillId): int => billsOf($this->acme)->filter(fn (Bill $bill): bool => $bill->type === BillType::LateFee
        && inOrganization($this->acme, fn () => BillItem::query()->where('bill_id', $bill->id)->value('meta'))['rent_bill_id'] === $rentBillId)->count();
    expect($feesFor($unpaid->id))->toBe(1)
        ->and(storedBill($this->acme, $fee)->status->value)->toBe('void')
        // The bill due on 4 January got its own fee on the 11th.
        ->and($feesFor(($this->billOf)($this->later)->id))->toBe(1);
});

it('lets owners and administrators set late fees on their own page; agents cannot open it', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    $page = LivewireHttp::snapshot($this->actingAs($admin)->get('/o/acme-homes/settings/payments/late-fees')->assertOk()
        ->assertSee('Late fees on rent')->assertSee('Charge late fees only if your tenancy agreements provide for them.'));
    billsFreshRequest();
    LivewireHttp::update($this->actingAs($admin), $page, ['enabled' => true, 'rate' => '2.5', 'graceDays' => '10', 'cap' => '50,000'], [['save', []]])
        ->assertOk()->assertSee('Saved. From tomorrow, a rent bill still unpaid 10 days after its due date gets a late fee of 2.5 % of what is unpaid, at most');

    $saved = LateFeeSettings::of($this->acme);
    expect([$saved->enabled, $saved->rateBps, $saved->graceDays, $saved->cap?->kobo])->toBe([true, 250, 10, 5_000_000]);

    billsFreshRequest();
    LivewireHttp::update($this->actingAs($admin), $page, ['rate' => '75'], [['save', []]])->assertOk()->assertSee('The percentage field must not be greater than 50.');
    expect(LateFeeSettings::of($this->acme)->rateBps)->toBe(250);

    billsFreshRequest();
    $this->actingAs($agent)->get('/o/acme-homes/settings/payments/late-fees')->assertForbidden();
});
