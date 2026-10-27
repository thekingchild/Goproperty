<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Leasing\Contracts\BillableLease;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;

require_once __DIR__.'/helpers.php';

/*
 * P03-03a: Contracts\LeaseBillingSchedule, how Billing (P03-04b) reads the
 * leases with rent to bill and moves them on, period by period through the
 * cycle calculator (ARCHITECTURE.md § 6.4; AGENT.md § 3 rule 3).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
});

afterEach(fn () => leasesFreshRequest());

/** @return list<int> the ids due on the date */
function dueLeaseIds(Organization $organization, string $date, int $lead = 7, int $after = 0, int $limit = 500): array
{
    return inOrganization($organization, fn (): array => array_map(
        fn (BillableLease $lease): int => $lease->id,
        app(LeaseBillingSchedule::class)->due(CarbonImmutable::parse($date), $lead, $after, $limit),
    ));
}

it('gives Billing the active and renewed leases whose next period is inside the lead window', function () {
    $monthly = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['billingCycle' => 'monthly', 'rent' => '125,000', 'dueDayOffset' => '5']);
    $later = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['startDate' => '2027-02-01', 'endDate' => '2028-01-31']);
    $prepaid = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['paidUntil' => '2027-12-31']);
    draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $ended = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $ended, 'Moved out'));
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    activeLease($other, joinAs(User::factory()->create(), $other), unitIn($other, buildingIn($other)), tenantIn($other));

    // 25 Dec + 7 days = 1 Jan: the January periods are due, February's not yet.
    expect(dueLeaseIds($this->acme, '2026-12-24'))->toBe([])
        ->and(dueLeaseIds($this->acme, '2026-12-25'))->toBe([$monthly->id])
        ->and(dueLeaseIds($this->acme, '2027-01-25'))->toBe([$monthly->id, $later->id])
        ->and(dueLeaseIds($this->acme, '2026-12-25', lead: 0))->toBe([])
        ->and(dueLeaseIds($this->acme, '2027-01-25', after: $monthly->id))->toBe([$later->id])
        ->and(dueLeaseIds($this->acme, '2027-01-25', limit: 1))->toBe([$monthly->id])
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($prepaid->id)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($ended->id)))->toBeNull();

    $billable = inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($monthly->id));
    expect($billable->contractNumber)->toBe('LS-ADR-2026-000001')
        ->and($billable->cycle)->toBe(BillingCycle::Monthly)
        ->and($billable->startDate->toDateString())->toBe('2027-01-01')
        ->and($billable->nextBillingDate->toDateString())->toBe('2027-01-01')
        ->and($billable->rent->kobo)->toBe(12_500_000)
        ->and($billable->dueDayOffset)->toBe(5)
        ->and($billable->propertyId)->toBe($this->palm->id);
});

it('moves a lease on one period at a time, once, and stops after its end date', function () {
    $lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['billingCycle' => 'quarterly', 'startDate' => '2027-01-31', 'endDate' => '2027-10-30', 'rent' => '400,000']);
    $advance = fn (string $period) => inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->advance($lease->id, CarbonImmutable::parse($period))?->toDateString());

    expect($advance('2027-01-31'))->toBe('2027-04-30')
        // Billing ran again for the same period: nothing moves.
        ->and($advance('2027-01-31'))->toBe('2027-04-30')
        ->and(fn () => $advance('2027-07-31'))->toThrow(LogicException::class)
        ->and($advance('2027-04-30'))->toBe('2027-07-31')
        ->and($advance('2027-07-31'))->toBe('2027-10-31')
        ->and(storedLease($this->acme, $lease)->next_billing_date?->toDateString())->toBe('2027-10-31')
        // The next period starts after the end date: nothing left to bill.
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($lease->id)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->advance(999_999, CarbonImmutable::parse('2027-01-31'))))->toBeNull();
});

it('locks a billable lease for Billing inside a transaction and gives it as it is now (P03-04b)', function () {
    $active = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['billingCycle' => 'monthly', 'rent' => '125,000']);
    $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $schedule = fn (): LeaseBillingSchedule => app(LeaseBillingSchedule::class);

    $locked = inOrganization($this->acme, fn () => DB::transaction(fn () => $schedule()->lock($active->id)));
    expect($locked)->toBeInstanceOf(BillableLease::class)
        ->and($locked->nextBillingDate->toDateString())->toBe('2027-01-01')
        ->and(inOrganization($this->acme, fn () => DB::transaction(fn () => $schedule()->lock($draft->id))))->toBeNull()
        ->and(inOrganization($this->acme, fn () => DB::transaction(fn () => $schedule()->lock(999_999))))->toBeNull();

    // Billed to its end: nothing left to lock for billing.
    inOrganization($this->acme, fn () => Lease::query()->whereKey($active->id)->update(['next_billing_date' => '2028-01-01']));
    expect(inOrganization($this->acme, fn () => DB::transaction(fn () => $schedule()->lock($active->id))))->toBeNull();

    // Another organization's lease is not there.
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $foreign = activeLease($other, joinAs(User::factory()->create(), $other), unitIn($other, buildingIn($other)), tenantIn($other));
    expect(inOrganization($this->acme, fn () => DB::transaction(fn () => $schedule()->lock($foreign->id))))->toBeNull();
});
