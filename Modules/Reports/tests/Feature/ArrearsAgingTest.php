<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Bills\BillService;
use Modules\Reports\Enums\AgingBucket;
use Modules\Reports\Reports\ArrearsReport;

require_once __DIR__.'/report-world.php';

/*
 * P08-01 AC "Arrears aging buckets (0–30/31–60/61–90/90+) reconcile
 * against a hand-computed fixture org" (report-world.php). After January
 * 2027, Adeyemi's monthly bills keep coming (bill generation on each
 * billing day, 7 days ahead) and nobody at Marina Heights pays again:
 *
 * - due 1 Jan: Bisi's rent, ₦100,000 of ₦200,000 still owed (her levy and
 *   Chuka's rent were paid; Kemi's and Tunde's yearly rents were paid);
 * - due 1 Feb: Bisi's ₦200,000 rent; Chuka's ₦150,000 rent less the
 *   ₦20,000 credit used: ₦130,000; Bisi's ₦25,000 levy was VOIDED;
 * - due 1 Mar, 1 Apr, 1 May and 1 Jun: Bisi's ₦200,000 rent and ₦25,000
 *   levy and Chuka's ₦150,000 rent: ₦375,000 each month.
 *
 * On 26 May 2027 (Lagos) those are 145, 114, 86, 55 and 25 days past due,
 * and June's are not yet due.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();

    foreach (['2027-02-22', '2027-03-25', '2027-04-24', '2027-05-25'] as $billingDay) {
        $this->travelTo($billingDay.' 00:10:00');
        runBillGeneration();
    }
});

afterEach(fn () => exportsFreshRequest());

/** [0–30, 31–60, 61–90, 90+] owed in kobo. */
function agingOf(ArrearsReport $arrears): array
{
    return array_map(fn (AgingBucket $bucket): int => $arrears->owedIn($bucket)->kobo, AgingBucket::cases());
}

it('acceptance: reconciles the aging buckets with the hand-computed fixture', function () {
    $this->travelTo('2027-05-26 10:00:00');
    $arrears = reportOf($this->acme, $this->owner, ['from' => '2027-05-01', 'to' => '2027-05-26'])->arrears;

    expect(agingOf($arrears))->toBe([37_500_000, 37_500_000, 37_500_000, 43_000_000])
        ->and($arrears->counts)->toBe(['0_30' => 3, '31_60' => 3, '61_90' => 3, '90_plus' => 3])
        ->and($arrears->total()->kobo)->toBe(155_500_000)
        ->and($arrears->notYetDue->kobo)->toBe(37_500_000)
        ->and($arrears->notYetDueCount)->toBe(3)
        ->and($arrears->tenantCount())->toBe(2)
        ->and($arrears->asOf->toDateString())->toBe('2027-05-26');

    // Bill by bill, oldest first: days past due and what is still owed. The void levy is not there.
    expect(array_map(fn ($line) => [$line->bill->dueDate->toDateString(), $line->bill->tenantName, $line->bill->typeLabel, $line->days, $line->bill->outstanding->kobo], $arrears->lines))
        ->toEqual([
            ['2027-01-01', 'Bisi Adeyinka', 'Rent', 145, 10_000_000],
            ['2027-02-01', 'Bisi Adeyinka', 'Rent', 114, 20_000_000],
            ['2027-02-01', 'Chuka Obi', 'Rent', 114, 13_000_000],
            ['2027-03-01', 'Bisi Adeyinka', 'Rent', 86, 20_000_000],
            ['2027-03-01', 'Chuka Obi', 'Rent', 86, 15_000_000],
            ['2027-03-01', 'Bisi Adeyinka', 'Utility', 86, 2_500_000],
            ['2027-04-01', 'Bisi Adeyinka', 'Rent', 55, 20_000_000],
            ['2027-04-01', 'Chuka Obi', 'Rent', 55, 15_000_000],
            ['2027-04-01', 'Bisi Adeyinka', 'Utility', 55, 2_500_000],
            ['2027-05-01', 'Bisi Adeyinka', 'Rent', 25, 20_000_000],
            ['2027-05-01', 'Chuka Obi', 'Rent', 25, 15_000_000],
            ['2027-05-01', 'Bisi Adeyinka', 'Utility', 25, 2_500_000],
        ])
        ->and(collect($arrears->lines)->contains(fn ($line) => $line->bill->id === $this->bisiFebLevy->id))->toBeFalse();

    // All of it at Marina Heights; Palm Court and Cedar House owe nothing.
    expect(array_map(fn ($row) => [$row['name'], $row['total']->kobo], $arrears->buildingRows()))->toBe([['Marina Heights', 155_500_000]])
        ->and(reportOf($this->acme, $this->owner, ['building' => (string) $this->palm->id])->arrears->total()->kobo)->toBe(0);

    // The page and the export reconcile with the buckets.
    reportsPage($this->owner)->assertOk()
        ->assertSeeInOrder(['Arrears aging', '0–30 days', '₦375,000', '31–60 days', '₦375,000', '61–90 days', '₦375,000', '90+ days', '₦430,000', 'Not yet due', '₦375,000'])
        ->assertSee('data-test="summary-arrears">₦1,555,000<', false);

    $rows = reportRows($this->owner, 'report_arrears');
    expect($rows[0])->toBe(['Bill', 'Tenant', 'Building', 'Unit', 'Type', 'Due date', 'Days past due', 'Aging', 'Amount', 'Paid', 'Owed'])
        ->and(count($rows))->toBe(13)
        ->and($rows[1])->toBe([storedBill($this->acme, $this->bisiJanRent)->number, 'Bisi Adeyinka', 'Marina Heights', 'Unit 1', 'Rent', '2027-01-01', '145', '90+ days', '200000.00', '100000.00', '100000.00']);

    $byBucket = [];

    foreach (array_slice($rows, 1) as $row) {
        $byBucket[$row[7]] = ($byBucket[$row[7]] ?? 0) + (int) round((float) $row[10] * 100);
    }

    expect($byBucket)->toBe(['90+ days' => 43_000_000, '61–90 days' => 37_500_000, '31–60 days' => 37_500_000, '0–30 days' => 37_500_000]);
});

it('moves bills across the bucket edges by their due dates: 30 and 31, 90 and 91 days, and the due day itself', function () {
    // 31 May: May's bills 30 days past due (0–30), April's 60 (31–60), March's 91 (90+); June's due tomorrow.
    $this->travelTo(CarbonImmutable::parse('2027-05-31 22:00:00', 'Africa/Lagos'));
    $may31 = reportOf($this->acme, $this->owner)->arrears;
    expect(agingOf($may31))->toBe([37_500_000, 37_500_000, 0, 80_500_000])
        ->and($may31->notYetDue->kobo)->toBe(37_500_000);

    // 30 May: March's bills 90 days past due, still 61–90.
    $this->travelTo('2027-05-30 10:00:00');
    expect(agingOf(reportOf($this->acme, $this->owner)->arrears))->toBe([37_500_000, 37_500_000, 37_500_000, 43_000_000]);

    // 1 June in Lagos (still 31 May in UTC): June's bills are due today (day 0, in arrears), May's 31 days past due.
    $this->travelTo(CarbonImmutable::parse('2027-06-01 00:30:00', 'Africa/Lagos'));
    expect(now('UTC')->toDateString())->toBe('2027-05-31');
    $june1 = reportOf($this->acme, $this->owner)->arrears;
    expect(agingOf($june1))->toBe([37_500_000, 37_500_000, 37_500_000, 80_500_000])
        ->and($june1->notYetDue->kobo)->toBe(0)
        ->and($june1->total()->kobo)->toBe(193_000_000);
});

it('leaves paid and void bills out, and counts a part payment by what is still owed', function () {
    $this->travelTo('2027-05-26 10:00:00');
    // Bisi clears January and pays ₦50,000 towards February.
    confirmedPayment($this->acme, $this->owner, $this->bisi, '150,000', [], [$this->bisiJanRent->id => '100,000', rentBillFor($this->acme, $this->bisiLease, '2027-02-01')->id => '50,000']);
    // March's levy is waived: voided.
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, levyBillFor($this->acme, $this->levy, '2027-03-01')), 'Levy waived for March'));

    $arrears = reportOf($this->acme, $this->owner)->arrears;

    expect(agingOf($arrears))->toBe([37_500_000, 37_500_000, 35_000_000, 28_000_000])
        ->and($arrears->counts)->toBe(['0_30' => 3, '31_60' => 3, '61_90' => 2, '90_plus' => 2])
        ->and($arrears->total()->kobo)->toBe(138_000_000);
});

it('keeps another organization\'s arrears out', function () {
    $this->travelTo('2027-05-26 10:00:00');

    // Bello's bills were paid (Musa) or are Harbour Point's own, at Bello only.
    $bello = reportOf($this->other, $this->otherOwner)->arrears;
    expect(collect($bello->lines)->pluck('bill.tenantName')->unique()->values()->all())->not->toContain('Bisi Adeyinka', 'Chuka Obi')
        ->and(reportOf($this->acme, $this->owner)->arrears->total()->kobo)->toBe(155_500_000);
});
