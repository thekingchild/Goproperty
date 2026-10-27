<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Documents\Verification\DocumentVerification;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Bills\BillIssuer;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Bills\PaymentReferences;
use Modules\Billing\Bills\RentBillGenerator;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Jobs\FlagOverdueBills;
use Modules\Billing\Jobs\GenerateBills;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Leasing\Contracts\BillableLease;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Random\Engine\Mt19937;
use Random\Randomizer;

require_once __DIR__.'/helpers.php';

/*
 * P03-04b: rent bills generated from leases by the scheduled
 * `bills-generate` job (ARCHITECTURE.md § 6.4, § 7): one bill per lease
 * period, inside the organization's lead window, with the calculator's
 * periods and due dates, numbered without gaps, never twice.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
});

afterEach(fn () => billsFreshRequest());

/** Travel to the day (Lagos) at the job's hour: 00:10 UTC is 01:10 in Lagos. */
function onBillingDay(string $date): void
{
    test()->travelTo($date.' 00:10:00');
}

/** A lease of a new unit of the building for a new tenant, activated. */
function billableLease(Organization $organization, User $by, mixed $building, array $terms = []): Lease
{
    return activeLease($organization, $by, unitIn($organization, $building), tenantIn($organization), $terms);
}

it('acceptance: creates no duplicate bills when the job runs again, the same day or later', function () {
    $annual = billableLease($this->acme, $this->owner, $this->palm);
    $monthly = billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'monthly', 'rent' => '125,000']);
    $issued = [];
    Event::listen(BillIssued::class, function (BillIssued $event) use (&$issued): void {
        $issued[] = $event->billId;
    });

    onBillingDay('2026-12-25');
    runBillGeneration();
    runBillGeneration();
    runBillGeneration();

    expect(billsOf($this->acme))->toHaveCount(2)
        ->and(billedPeriodsOf($this->acme, $annual))->toBe([['2027-01-01', '2027-12-31', '2027-01-01']])
        ->and(billedPeriodsOf($this->acme, $monthly))->toBe([['2027-01-01', '2027-01-31', '2027-01-01']]);

    // The next days, still nothing new until February's window opens.
    foreach (['2026-12-26', '2027-01-01', '2027-01-24'] as $day) {
        onBillingDay($day);
        runBillGeneration();
        expect(billsOf($this->acme))->toHaveCount(2);
    }

    onBillingDay('2027-01-25');
    runBillGeneration();
    runBillGeneration();

    $bills = billsOf($this->acme);
    expect($bills)->toHaveCount(3)
        ->and(billedPeriodsOf($this->acme, $monthly))->toBe([['2027-01-01', '2027-01-31', '2027-01-01'], ['2027-02-01', '2027-02-28', '2027-02-01']])
        ->and($issued)->toBe($bills->pluck('id')->all())
        ->and(AuditEntry::query()->where('event', 'bill.issued')->count())->toBe(3)
        ->and(inOrganization($this->acme, fn () => DocumentVerification::query()->where('kind', 'invoice')->count()))->toBe(3)
        ->and($bills->pluck('number')->all())->toBe(['INV-ADR-2026-000001', 'INV-ADR-2026-000002', 'INV-ADR-2027-000001'])
        ->and(inOrganization($this->acme, fn () => Lease::query()->findOrFail($monthly->id)->next_billing_date?->toDateString()))->toBe('2027-03-01');
});

it('acceptance: never bills a period twice, even for a lease that was not moved on, and the database refuses a second bill', function () {
    $lease = billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'monthly', 'rent' => '125,000']);
    onBillingDay('2026-12-25');
    runBillGeneration();

    // As if a run had made the bill but not moved the lease on.
    inOrganization($this->acme, fn () => Lease::query()->whereKey($lease->id)->update(['next_billing_date' => '2027-01-01']));
    runBillGeneration();

    expect(billsOf($this->acme))->toHaveCount(1)
        ->and(inOrganization($this->acme, fn () => Lease::query()->findOrFail($lease->id)->next_billing_date?->toDateString()))->toBe('2027-02-01');

    // The natural key (lease, type, period start) is unique in the database itself.
    $bill = billsOf($this->acme)->sole();
    $copy = fn () => inOrganization($this->acme, fn () => DB::table('bills')->insert([
        ...collect($bill->getAttributes())->except(['id'])->all(),
        'payment_reference' => 'GP-0000000',
        'number' => 'INV-ADR-2026-999999',
        'verification_code' => '0000000000',
    ]));
    expect($copy)->toThrow(UniqueConstraintViolationException::class);
});

it('bills annual, weekly and semi-annual leases with exactly the calculator\'s periods, each on its billing day, until the lease ends', function (string $cycle, string $start, string $end, string $rent, int $dueDayOffset, array $literal) {
    $lease = billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => $cycle, 'startDate' => $start, 'endDate' => $end, 'rent' => $rent, 'dueDayOffset' => (string) $dueDayOffset]);
    $calculator = app(CycleCalculator::class);
    $billingCycle = BillingCycle::from($cycle);
    $count = count($literal);
    $expected = calculatorPeriods($start, $billingCycle, $count, $dueDayOffset);

    // The test's own dates agree with the calculator's.
    expect($expected)->toBe($literal);

    foreach ($expected as $index => [$periodStart]) {
        $billingDay = $calculator->billingDate(CarbonImmutable::parse($periodStart), 7)->toDateString();

        // The day before the period's billing day: not yet.
        onBillingDay($calculator->daysAfter(CarbonImmutable::parse($billingDay), -1)->toDateString());
        runBillGeneration();
        expect(billedPeriodsOf($this->acme, $lease))->toHaveCount($index);

        onBillingDay($billingDay);
        runBillGeneration();
        expect(billedPeriodsOf($this->acme, $lease))->toBe(array_slice($expected, 0, $index + 1));
    }

    // After the last period, nothing more: the lease is billed to its end.
    onBillingDay($calculator->daysAfter(CarbonImmutable::parse($end), 30)->toDateString());
    runBillGeneration();

    $bills = billsOf($this->acme);
    expect(billedPeriodsOf($this->acme, $lease))->toBe($expected)
        ->and($bills->map(fn (Bill $bill): int => $bill->amount_kobo->kobo)->unique()->values()->all())->toBe([$lease->rent_amount_kobo->kobo])
        ->and($bills->pluck('type')->unique()->values()->all())->toBe([BillType::Rent]);
})->with([
    // 29 Feb anchor: 28 Feb in common years (ARCHITECTURE.md § 6.3).
    'annual from 29 Feb 2028' => ['annual', '2028-02-29', '2032-02-28', '1,800,000', 0, [
        ['2028-02-29', '2029-02-27', '2028-02-29'],
        ['2029-02-28', '2030-02-27', '2029-02-28'],
        ['2030-02-28', '2031-02-27', '2030-02-28'],
        ['2031-02-28', '2032-02-28', '2031-02-28'],
    ]],
    // Month-end clamp: 31 Aug → 29 Feb → 31 Aug → 28 Feb.
    'semi-annual from 31 Aug 2027' => ['semi_annual', '2027-08-31', '2030-08-30', '900,000', 3, [
        ['2027-08-31', '2028-02-28', '2027-09-03'],
        ['2028-02-29', '2028-08-30', '2028-03-03'],
        ['2028-08-31', '2029-02-27', '2028-09-03'],
        ['2029-02-28', '2029-08-30', '2029-03-03'],
        ['2029-08-31', '2030-02-27', '2029-09-03'],
        ['2030-02-28', '2030-08-30', '2030-03-03'],
    ]],
    // Plain 7-day counts from a Wednesday, due two days in.
    'weekly from Wed 6 Jan 2027' => ['weekly', '2027-01-06', '2027-03-02', '25,000', 2, [
        ['2027-01-06', '2027-01-12', '2027-01-08'],
        ['2027-01-13', '2027-01-19', '2027-01-15'],
        ['2027-01-20', '2027-01-26', '2027-01-22'],
        ['2027-01-27', '2027-02-02', '2027-01-29'],
        ['2027-02-03', '2027-02-09', '2027-02-05'],
        ['2027-02-10', '2027-02-16', '2027-02-12'],
        ['2027-02-17', '2027-02-23', '2027-02-19'],
        ['2027-02-24', '2027-03-02', '2027-02-26'],
    ]],
]);

it('bills a period only inside the organization\'s lead window, each organization by its own setting', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $soon = Organization::factory()->create(['slug' => 'chika-estates', 'name' => 'Chika Estates']);
    $soonOwner = joinAs(User::factory()->create(), $soon);
    setLeadDays($other, 14);
    setLeadDays($soon, 0);

    $terms = ['billingCycle' => 'monthly', 'startDate' => '2027-02-01', 'endDate' => '2028-01-31', 'rent' => '125,000'];
    billableLease($this->acme, $this->owner, $this->palm, $terms);
    billableLease($other, $otherOwner, buildingIn($other), $terms);
    billableLease($soon, $soonOwner, buildingIn($soon), $terms);
    $firstBilled = [];

    foreach (range(14, 31) as $day) {
        $date = sprintf('2027-01-%02d', $day);
        onBillingDay($date);
        runBillGeneration();

        foreach (['acme' => $this->acme, 'other' => $other, 'soon' => $soon] as $key => $organization) {
            if (! isset($firstBilled[$key]) && billsOf($organization)->isNotEmpty()) {
                $firstBilled[$key] = $date;
            }
        }
    }

    onBillingDay('2027-02-01');
    runBillGeneration();
    $firstBilled['soon'] ??= billsOf($soon)->isNotEmpty() ? '2027-02-01' : null;

    // Default 7 days, 14 days, and 0 (on the period's first day).
    expect($firstBilled)->toEqual(['acme' => '2027-01-25', 'other' => '2027-01-18', 'soon' => '2027-02-01'])
        ->and(inOrganization($this->acme, fn () => app(BillService::class)->leadDays()))->toBe(7)
        ->and(inOrganization($other, fn () => app(BillService::class)->leadDays()))->toBe(14);

    foreach ([$this->acme, $other, $soon] as $organization) {
        expect(billsOf($organization))->toHaveCount(1)
            ->and(billsOf($organization)->sole()->period_start?->toDateString())->toBe('2027-02-01');
    }
});

it('respects paid until: no bill for a period already paid, the first bill is the period after it', function () {
    $prepaidYear = billableLease($this->acme, $this->owner, $this->palm, ['startDate' => '2026-01-01', 'endDate' => '2028-12-31', 'paidUntil' => '2026-12-31']);
    $prepaidMonths = billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'monthly', 'startDate' => '2026-10-01', 'endDate' => '2027-09-30', 'rent' => '125,000', 'paidUntil' => '2026-12-31']);

    onBillingDay('2026-12-20');
    runBillGeneration();
    expect(billsOf($this->acme))->toHaveCount(0);

    onBillingDay('2026-12-25');
    runBillGeneration();

    expect(billedPeriodsOf($this->acme, $prepaidYear))->toBe([['2027-01-01', '2027-12-31', '2027-01-01']])
        ->and(billedPeriodsOf($this->acme, $prepaidMonths))->toBe([['2027-01-01', '2027-01-31', '2027-01-01']])
        ->and(billsOf($this->acme)->pluck('status')->unique()->values()->all())->toBe([BillStatus::Unpaid]);
});

it('bills every period a lease is behind by, oldest first, the ones already due as overdue', function () {
    // Started in October with nothing paid: October to December are owed.
    $lease = billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'monthly', 'startDate' => '2026-10-01', 'endDate' => '2027-09-30', 'rent' => '125,000']);

    onBillingDay('2026-12-20');
    runBillGeneration();

    expect(billedPeriodsOf($this->acme, $lease))->toBe([
        ['2026-10-01', '2026-10-31', '2026-10-01'],
        ['2026-11-01', '2026-11-30', '2026-11-01'],
        ['2026-12-01', '2026-12-31', '2026-12-01'],
    ])
        ->and(billsOf($this->acme)->pluck('status')->unique()->values()->all())->toBe([BillStatus::Overdue])
        ->and(billsOf($this->acme)->pluck('number')->all())->toBe(['INV-ADR-2026-000001', 'INV-ADR-2026-000002', 'INV-ADR-2026-000003']);
});

it('stops billing a lease that ended early, never bills drafts, and bills a renewed lease to its end and then its renewal', function () {
    $ending = billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'monthly', 'rent' => '125,000']);
    $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['billingCycle' => 'monthly', 'rent' => '125,000']);
    $renewed = billableLease($this->acme, $this->owner, $this->palm);

    onBillingDay('2026-12-25');
    runBillGeneration();
    expect(billsOf($this->acme))->toHaveCount(2);

    $this->travelTo('2027-01-10 10:00:00');
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $ending, 'Moved out'));
    $renewal = inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $renewed, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31', 'rent' => '1,650,000'])));
    // The contract PDF is not under test here (P03-03b renders it on the pdf queue).
    withoutContractPdfs(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $renewal)));

    foreach (['2027-01-25', '2027-06-25', '2027-12-25'] as $day) {
        onBillingDay($day);
        runBillGeneration();
    }

    expect(billedPeriodsOf($this->acme, $ending))->toBe([['2027-01-01', '2027-01-31', '2027-01-01']])
        ->and(billedPeriodsOf($this->acme, $draft))->toBe([])
        ->and(billedPeriodsOf($this->acme, $renewed))->toBe([['2027-01-01', '2027-12-31', '2027-01-01']])
        ->and(billedPeriodsOf($this->acme, storedLease($this->acme, $renewal)))->toBe([['2028-01-01', '2028-12-31', '2028-01-01']])
        ->and(billsOf($this->acme)->last()->amount_kobo->kobo)->toBe(165_000_000);
});

it('issues each bill with its number, payment reference, verification code, branding as at issue and one rent line', function () {
    $lease = billableLease($this->acme, $this->owner, $this->palm, ['dueDayOffset' => '5']);
    onBillingDay('2026-12-25');
    runBillGeneration();

    $bill = billsOf($this->acme)->sole();
    $item = inOrganization($this->acme, fn () => BillItem::query()->where('bill_id', $bill->id)->sole());
    $audit = AuditEntry::query()->where('event', 'bill.issued')->sole();

    expect($bill->number)->toBe('INV-ADR-2026-000001')
        ->and($bill->type)->toBe(BillType::Rent)
        ->and($bill->billable_type)->toBe(BillableType::Lease)
        ->and($bill->billable_id)->toBe($lease->id)
        ->and($bill->lease_id)->toBe($lease->id)
        ->and($bill->tenant_profile_id)->toBe($lease->tenant_profile_id)
        ->and($bill->property_id)->toBe($this->palm->id)
        ->and($bill->unit_id)->toBe($lease->unit_id)
        ->and($bill->payment_reference)->toMatch('/^GP-[0-9A-HJKMNP-TV-Z]{7}$/')
        ->and($bill->verification_code)->toMatch('/^[0-9A-HJKMNP-TV-Z]{10}$/')
        ->and(inOrganization($this->acme, fn () => DocumentVerification::query()->where('code', $bill->verification_code)->value('kind')))->toBe(BillIssuer::VERIFICATION_KIND)
        ->and($bill->description)->toBe('Rent, 01/01/2027 to 31/12/2027')
        ->and($bill->amount_kobo->kobo)->toBe(150_000_000)
        ->and($bill->paid_kobo->kobo)->toBe(0)
        ->and($bill->outstanding()->kobo)->toBe(150_000_000)
        ->and($bill->due_date->toDateString())->toBe('2027-01-06')
        ->and($bill->status)->toBe(BillStatus::Unpaid)
        ->and($bill->issued_at->toDateTimeString())->toBe('2026-12-25 00:10:00')
        ->and($bill->created_by_user_id)->toBeNull()
        ->and($bill->branding_snapshot['branding']['company_name'])->toBe('Adeyemi Realty Ltd')
        ->and($bill->branding_snapshot['branding']['invoice_prefix'])->toBe('INV-ADR')
        ->and($bill->branding_snapshot['version'])->toBe(1)
        ->and($item->label)->toBe('Rent')
        ->and($item->note)->toBe('Yearly rent, lease '.storedLease($this->acme, $lease)->contract_number)
        ->and($item->amount_kobo->kobo)->toBe(150_000_000)
        ->and($item->period_start?->toDateString())->toBe('2027-01-01')
        ->and($item->period_end?->toDateString())->toBe('2027-12-31')
        ->and($item->meta)->toEqual(['cycle' => 'annual', 'contract_number' => storedLease($this->acme, $lease)->contract_number, 'period_index' => 0])
        ->and($audit->log_name)->toBe('bills')
        ->and($audit->subject_id)->toBe($bill->id)
        ->and($audit->causer_id)->toBeNull()
        ->and($audit->properties['number'])->toBe('INV-ADR-2026-000001')
        ->and($audit->properties['amount_kobo'])->toBe(150_000_000)
        ->and($audit->properties['period_start'])->toBe('2027-01-01');

    // Lines are history: never changed or deleted.
    expect(fn () => inOrganization($this->acme, fn () => $item->forceFill(['label' => 'Changed'])->save()))->toThrow(LogicException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $item->delete()))->toThrow(LogicException::class);
});

it('gives each organization its own gap-free invoice numbers and unique payment references', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $otherBuilding = buildingIn($other);

    foreach (range(1, 3) as $n) {
        billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'monthly', 'rent' => '125,000']);
        billableLease($other, $otherOwner, $otherBuilding, ['billingCycle' => 'monthly', 'rent' => '100,000']);
    }

    onBillingDay('2026-12-25');
    runBillGeneration();
    onBillingDay('2027-01-25');
    runBillGeneration();

    $ours = billsOf($this->acme);
    $theirs = billsOf($other);
    $references = [...$ours->pluck('payment_reference')->all(), ...$theirs->pluck('payment_reference')->all()];

    expect($ours->pluck('number')->all())->toBe(['INV-ADR-2026-000001', 'INV-ADR-2026-000002', 'INV-ADR-2026-000003', 'INV-ADR-2027-000001', 'INV-ADR-2027-000002', 'INV-ADR-2027-000003'])
        ->and($theirs->pluck('number')->all())->toBe(['INV-BEH-2026-000001', 'INV-BEH-2026-000002', 'INV-BEH-2026-000003', 'INV-BEH-2027-000001', 'INV-BEH-2027-000002', 'INV-BEH-2027-000003'])
        ->and(array_unique($references))->toHaveCount(12)
        ->and($ours->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and($theirs->pluck('organization_id')->unique()->all())->toBe([$other->id]);
});

it('draws another payment reference when one is taken by any organization\'s bill', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    billableLease($other, joinAs(User::factory()->create(), $other), buildingIn($other));
    billableLease($this->acme, $this->owner, $this->palm);
    $seeded = fn (): PaymentReferences => new PaymentReferences(new Randomizer(new Mt19937(2026)));
    $draws = [$seeded()->generate()];
    $second = $seeded();
    $second->generate();
    $draws[] = $second->generate();

    // Each organization's run draws from the same seeded sequence: the
    // second bill's first draw is taken, so it gets the next one.
    app()->bind(PaymentReferences::class, $seeded);
    onBillingDay('2026-12-25');
    inOrganization($other, fn () => app(RentBillGenerator::class)->generate());
    inOrganization($this->acme, fn () => app(RentBillGenerator::class)->generate());

    expect(billsOf($other)->sole()->payment_reference)->toBe($draws[0])
        ->and(billsOf($this->acme)->sole()->payment_reference)->toBe($draws[1])
        ->and($draws[0])->not->toBe($draws[1]);
});

it('keeps billing suspended and restricted organizations, and bills nothing for a cancelled one', function () {
    $suspended = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    $cancelled = Organization::factory()->create(['slug' => 'chika-estates', 'name' => 'Chika Estates']);
    $restricted = Organization::factory()->create(['slug' => 'dayo-lets', 'name' => 'Dayo Lets']);

    foreach ([$suspended, $cancelled, $restricted] as $organization) {
        billableLease($organization, joinAs(User::factory()->create(), $organization), buildingIn($organization));
    }

    $suspended->forceFill(['status' => OrganizationStatus::Suspended])->save();
    $cancelled->forceFill(['status' => OrganizationStatus::Cancelled])->save();
    $restricted->forceFill(['status' => OrganizationStatus::Restricted])->save();

    onBillingDay('2026-12-25');
    runBillGeneration();

    expect(billsOf($suspended))->toHaveCount(1)
        ->and(billsOf($restricted))->toHaveCount(1)
        ->and(billsOf($cancelled))->toHaveCount(0);
});

it('reports a lease that fails and still bills the others', function () {
    Exceptions::fake();
    $broken = billableLease($this->acme, $this->owner, $this->palm);
    $fine = billableLease($this->acme, $this->owner, $this->palm);
    $real = app(LeaseBillingSchedule::class);

    app()->bind(LeaseBillingSchedule::class, fn () => new class($real, $broken->id) implements LeaseBillingSchedule
    {
        public function __construct(private LeaseBillingSchedule $real, private int $brokenId) {}

        public function due(DateTimeInterface $date, int $leadDays = CycleCalculator::DEFAULT_LEAD_DAYS, int $afterId = 0, int $limit = 500): array
        {
            return $this->real->due($date, $leadDays, $afterId, $limit);
        }

        public function find(int $leaseId): ?BillableLease
        {
            return $this->real->find($leaseId);
        }

        public function lock(int $leaseId): ?BillableLease
        {
            return $leaseId === $this->brokenId ? throw new RuntimeException('Lease row unreadable.') : $this->real->lock($leaseId);
        }

        public function advance(int $leaseId, DateTimeInterface $periodStart): ?CarbonImmutable
        {
            return $this->real->advance($leaseId, $periodStart);
        }
    });

    onBillingDay('2026-12-25');
    runBillGeneration();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Lease row unreadable.');
    expect(billedPeriodsOf($this->acme, $broken))->toBe([])
        ->and(billedPeriodsOf($this->acme, $fine))->toHaveCount(1);
});

it('reports an organization that fails and still bills the next ones', function () {
    Exceptions::fake();
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    billableLease($this->acme, $this->owner, $this->palm);
    billableLease($other, joinAs(User::factory()->create(), $other), buildingIn($other));
    $real = app(LeaseBillingSchedule::class);
    $brokenId = $this->acme->id;

    app()->bind(LeaseBillingSchedule::class, fn () => new class($real, $brokenId) implements LeaseBillingSchedule
    {
        public function __construct(private LeaseBillingSchedule $real, private int $brokenId) {}

        public function due(DateTimeInterface $date, int $leadDays = CycleCalculator::DEFAULT_LEAD_DAYS, int $afterId = 0, int $limit = 500): array
        {
            return app(OrganizationContext::class)->id() === $this->brokenId ? throw new RuntimeException('Leases unreadable.') : $this->real->due($date, $leadDays, $afterId, $limit);
        }

        public function find(int $leaseId): ?BillableLease
        {
            return $this->real->find($leaseId);
        }

        public function lock(int $leaseId): ?BillableLease
        {
            return $this->real->lock($leaseId);
        }

        public function advance(int $leaseId, DateTimeInterface $periodStart): ?CarbonImmutable
        {
            return $this->real->advance($leaseId, $periodStart);
        }
    });

    onBillingDay('2026-12-25');
    runBillGeneration();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Leases unreadable.');
    expect(billsOf($this->acme))->toHaveCount(0)
        ->and(billsOf($other))->toHaveCount(1)
        ->and(Cache::lock(GenerateBills::LOCK, 1)->get())->toBeTrue();
});

it('stops when told to and carries on where it stopped, billing each period once', function () {
    foreach (range(1, 4) as $n) {
        billableLease($this->acme, $this->owner, $this->palm, ['billingCycle' => 'weekly', 'startDate' => '2026-12-02', 'endDate' => '2027-06-29', 'rent' => '25,000']);
    }

    onBillingDay('2026-12-20');
    $calls = 0;
    $stopped = inOrganization($this->acme, fn () => app(RentBillGenerator::class)->generate(function () use (&$calls): bool {
        return ++$calls > 5;
    }));
    $partial = billsOf($this->acme)->count();
    $finished = inOrganization($this->acme, fn () => app(RentBillGenerator::class)->generate());

    // Four leases × the weeks from 2 Dec to the one starting 23 Dec (27 Dec − 7 days).
    expect($stopped)->toBeFalse()
        ->and($partial)->toBeGreaterThan(0)->toBeLessThan(16)
        ->and($finished)->toBeTrue()
        ->and(billsOf($this->acme))->toHaveCount(16)
        ->and(billsOf($this->acme)->map(fn (Bill $bill): string => $bill->lease_id.'@'.$bill->period_start?->toDateString())->unique())->toHaveCount(16)
        ->and(billsOf($this->acme)->pluck('number')->last())->toBe('INV-ADR-2026-000016');
});

it('runs one copy at a time and from the organization a continued run starts at', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    billableLease($this->acme, $this->owner, $this->palm);
    billableLease($other, joinAs(User::factory()->create(), $other), buildingIn($other));
    onBillingDay('2026-12-25');

    // Another copy is working: this one leaves it the work.
    $lock = Cache::lock(GenerateBills::LOCK, 60);
    expect($lock->get())->toBeTrue();
    GenerateBills::dispatchSync();
    expect(billsOf($this->acme))->toHaveCount(0);
    $lock->release();

    // A continued run starts at its organization.
    GenerateBills::dispatchSync($other->id);
    expect(billsOf($this->acme))->toHaveCount(0)
        ->and(billsOf($other))->toHaveCount(1);

    GenerateBills::dispatchSync();
    expect(billsOf($this->acme))->toHaveCount(1)
        ->and(Cache::lock(GenerateBills::LOCK, 1)->get())->toBeTrue();
});

it('schedules bill generation at 00:10 and overdue flagging at 01:00 on the billing queue, queued once at a time', function () {
    Queue::fake();

    billingScheduledEvent('bills-generate', '10 0 * * *')->run(app());
    billingScheduledEvent('bills-generate', '10 0 * * *')->run(app());
    billingScheduledEvent('bills-flag-overdue', '0 1 * * *')->run(app());

    Queue::assertPushedOn('billing', GenerateBills::class);
    Queue::assertPushedOn('billing', FlagOverdueBills::class);
    // Unique until it starts: the second copy was not queued.
    Queue::assertPushed(GenerateBills::class, 1);
});
