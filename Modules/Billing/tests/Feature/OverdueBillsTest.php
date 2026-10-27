<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Events\BillOverdue;
use Modules\Billing\Models\Bill;

require_once __DIR__.'/helpers.php';

/*
 * P03-04b (P03 decision 5): the daily `bills-flag-overdue` job
 * (ARCHITECTURE.md § 7, 01:00) moves bills still unpaid or partly paid
 * after their due date (Lagos) to `overdue`, which the OVERDUE badge shows
 * (BRANDING_DOCUMENTS.md § 2). Per organization, once per bill.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    // Four yearly leases from 1 Jan 2027, due 1 Jan (one due on 4 Jan).
    $this->unpaid = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo']));
    $this->part = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $this->paid = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $this->later = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['dueDayOffset' => '3']);
    $this->voided = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    runBillGeneration();

    $billOf = fn ($lease): Bill => billsOf($this->acme)->firstWhere('lease_id', $lease->id);
    inOrganization($this->acme, function () use ($billOf): void {
        DB::transaction(function () use ($billOf): void {
            app(BillLedger::class)->apply($billOf($this->part)->id, Money::naira(500_000));
            app(BillLedger::class)->apply($billOf($this->paid)->id, Money::naira(1_500_000));
        });
        app(BillService::class)->void($this->owner, $billOf($this->voided), 'Wrong tenant');
    });
    $this->billOf = $billOf;
});

afterEach(fn () => billsFreshRequest());

/** @return array<string, string> lease name => bill status */
function overdueStatuses(object $test): array
{
    return collect(['unpaid', 'part', 'paid', 'later', 'voided'])
        ->mapWithKeys(fn (string $name): array => [$name => storedBill($test->acme, ($test->billOf)($test->{$name}))->status->value])
        ->all();
}

it('flags bills unpaid or partly paid after their due date as overdue, the day after it in Lagos, once', function () {
    $flagged = [];
    Event::listen(BillOverdue::class, function (BillOverdue $event) use (&$flagged): void {
        $flagged[] = $event->billId;
    });

    // Due on 1 Jan: still not overdue on its due day (23:30 Lagos = 22:30 UTC).
    $this->travelTo('2027-01-01 22:30:00');
    runOverdueFlagging();
    expect(overdueStatuses($this))->toBe(['unpaid' => 'unpaid', 'part' => 'partially_paid', 'paid' => 'paid', 'later' => 'unpaid', 'voided' => 'void']);

    // 2 Jan at 00:30 in Lagos is still 1 Jan in UTC.
    $this->travelTo('2027-01-01 23:30:00');
    runOverdueFlagging();
    expect(overdueStatuses($this))->toBe(['unpaid' => 'overdue', 'part' => 'overdue', 'paid' => 'paid', 'later' => 'unpaid', 'voided' => 'void']);

    runOverdueFlagging();
    $this->travelTo('2027-01-05 00:00:00');
    runOverdueFlagging();
    runOverdueFlagging();

    expect(overdueStatuses($this))->toBe(['unpaid' => 'overdue', 'part' => 'overdue', 'paid' => 'paid', 'later' => 'overdue', 'voided' => 'void'])
        ->and($flagged)->toEqualCanonicalizing([($this->billOf)($this->unpaid)->id, ($this->billOf)($this->part)->id, ($this->billOf)($this->later)->id])
        ->and($flagged)->toHaveCount(3);
});

it('shows the OVERDUE badge on the bills list and the bill page', function () {
    $this->travelTo('2027-01-03 00:00:00');
    runOverdueFlagging();
    $bill = ($this->billOf)($this->unpaid);

    billsPage($this->owner)->assertOk()->assertSeeInOrder(['data-test="status-'.$bill->id.'"', 'Overdue'], false);
    billsPage($this->owner, '?status=overdue')->assertOk()->assertSee('data-bill="'.$bill->id.'"', false)->assertDontSee('data-bill="'.($this->billOf)($this->paid)->id.'"', false);
    billsPage($this->owner, "/{$bill->id}")->assertOk()->assertSeeInOrder(['data-test="bill-status"', 'Overdue'], false);
});

it('flags each organization\'s bills in its own context, suspended ones too', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    activeLease($other, joinAs(User::factory()->create(), $other), unitIn($other, buildingIn($other)), tenantIn($other));
    runBillGeneration();
    $other->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $this->travelTo('2027-01-03 00:00:00');
    runOverdueFlagging();

    expect(billsOf($other)->sole()->status)->toBe(BillStatus::Overdue)
        ->and(storedBill($this->acme, ($this->billOf)($this->unpaid))->status)->toBe(BillStatus::Overdue);
});

it('leaves a bill paid meanwhile alone, and a paid overdue bill is paid', function () {
    $this->travelTo('2027-01-03 00:00:00');
    $bill = ($this->billOf)($this->unpaid);
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($bill->id, Money::naira(1_500_000))));
    runOverdueFlagging();

    expect(storedBill($this->acme, $bill)->status)->toBe(BillStatus::Paid);

    $part = ($this->billOf)($this->part);
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($part->id, Money::naira(1_000_000))));
    expect(storedBill($this->acme, $part)->status)->toBe(BillStatus::Paid);
});
