<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/report-world.php';

/*
 * P08-01 AC "every report has a feature test with seeded edge data (voided
 * bills excluded, refunds netted)": collections vs billed, against the
 * hand-computed fixture (report-world.php). Adeyemi's bills:
 *
 * - issued 25 Dec 2026 for January: Kemi's ₦1,500,000 (Palm Court), Tunde's
 *   ₦1,800,000 (Cedar House), Bisi's ₦200,000 rent and ₦25,000 levy and
 *   Chuka's ₦150,000 rent (Marina Heights): ₦3,675,000, of which ₦25,000
 *   utilities;
 * - issued 25 Jan 2027 for February: Bisi's ₦200,000 and Chuka's ₦150,000
 *   rent (₦350,000) and Bisi's ₦25,000 levy, voided on 26 Jan (left out).
 *
 * Collected in January 2027: Kemi ₦1,500,000, Bisi ₦100,000 (part of her
 * rent) and ₦25,000 (levy), Chuka ₦150,000 of his ₦180,000 (the ₦30,000
 * over went to credit and was refunded to him: never counted), Tunde
 * ₦1,800,000, and Chuka's ₦20,000 paid ahead, counted when it was used
 * for February's rent on 25 Jan: ₦3,595,000 (₦3,570,000 rent, ₦25,000
 * utilities). Bello Homes' ₦900,000 is another organization's.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
});

afterEach(fn () => exportsFreshRequest());

it('acceptance: reconciles collections vs billed with the hand-computed fixture, void bill excluded and refund netted', function () {
    $report = reportOf($this->acme, $this->owner, ['from' => '2026-12-01', 'to' => '2027-01-26'])->collections;

    expect(array_map(fn ($month) => [$month->month->key, $month->totals->billedTotal()->kobo, $month->totals->collectedTotal()->kobo], $report->months))
        ->toBe([['2026-12', 367_500_000, 0], ['2027-01', 35_000_000, 359_500_000]])
        ->and(kobos($report->totals->billed))->toBe(['rent' => 400_000_000, 'utility' => 2_500_000, 'other' => 0])
        ->and(kobos($report->totals->collected))->toBe(['rent' => 357_000_000, 'utility' => 2_500_000, 'other' => 0])
        ->and($report->totals->billedTotal()->kobo)->toBe(402_500_000)
        ->and($report->totals->collectedTotal()->kobo)->toBe(359_500_000)
        // 3,595,000 / 4,025,000 = 89.31…%, shown to a tenth, rounded down.
        ->and($report->totals->rate()?->label())->toBe('89.3%')
        ->and($report->totals->rateOf('utility')?->label())->toBe('100.0%')
        ->and($report->totals->rateOf('other'))->toBeNull();

    // January alone: collected more than was billed in it (December's bills were paid in January).
    $january = reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-31'])->collections;
    expect($january->totals->billedTotal()->kobo)->toBe(35_000_000)
        ->and($january->totals->collectedTotal()->kobo)->toBe(359_500_000)
        ->and($january->totals->rate()?->label())->toBe('1027.1%');

    // The page and the export say the same.
    reportsPage($this->owner, 'from=2026-12-01&to=2027-01-26')->assertOk()
        ->assertSeeInOrder(['Collections vs billed', 'Dec 2026', '₦3,675,000', '₦0', 'Jan 2027', '₦350,000', '₦3,595,000'])
        ->assertSee('data-test="summary-billed">₦4,025,000<', false)
        ->assertSee('data-test="summary-collected">₦3,595,000<', false)
        ->assertSee('Collection rate 89.3%');

    $rows = reportRows($this->owner, 'report_collections', 'from=2026-12-01&to=2027-01-26');
    expect($rows)->toBe([
        ['Month', 'From', 'To', 'Billed', 'Collected', 'Collection rate', 'Rent billed', 'Rent collected', 'Utilities billed', 'Utilities collected', 'Other billed', 'Other collected'],
        ['Dec 2026', '2026-12-01', '2026-12-31', '3675000.00', '0.00', '0.0%', '3650000.00', '0.00', '25000.00', '0.00', '0.00', '0.00'],
        ['Jan 2027', '2027-01-01', '2027-01-26', '350000.00', '3595000.00', '1027.1%', '350000.00', '3570000.00', '0.00', '25000.00', '0.00', '0.00'],
    ]);
});

it('counts a voided receipt, negative, in the month it was voided, and never changes the month of the payment', function () {
    $this->travelTo('2027-02-03 10:00:00');
    voidReceiptAlone($this->acme, $this->owner, receiptOf($this->acme, $this->chukaPayment));

    $report = reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-02-03'])->collections;

    expect(array_map(fn ($month) => [$month->month->key, $month->totals->billedTotal()->kobo, $month->totals->collectedTotal()->kobo], $report->months))
        ->toBe([['2027-01', 35_000_000, 359_500_000], ['2027-02', 0, -15_000_000]])
        ->and($report->totals->collectedTotal()->kobo)->toBe(344_500_000)
        ->and($report->months[1]->totals->rate())->toBeNull();
});

it('keeps to the picked building, and the other organization\'s money out', function () {
    $marina = reportOf($this->acme, $this->owner, ['from' => '2026-12-01', 'to' => '2027-01-26', 'building' => (string) $this->marina->id])->collections;

    // Marina Heights: ₦375,000 billed in December, ₦350,000 in January (the void levy left out);
    // collected ₦100,000 + ₦25,000 + ₦150,000 + ₦20,000 credit.
    expect($marina->totals->billedTotal()->kobo)->toBe(72_500_000)
        ->and($marina->totals->collectedTotal()->kobo)->toBe(29_500_000)
        ->and(kobos($marina->totals->collected))->toBe(['rent' => 27_000_000, 'utility' => 2_500_000, 'other' => 0]);

    $bello = reportOf($this->other, $this->otherOwner, ['from' => '2026-12-01', 'to' => '2027-01-26'])->collections;
    expect($bello->totals->billedTotal()->kobo)->toBe(330_000_000)
        ->and($bello->totals->collectedTotal()->kobo)->toBe(90_000_000);
});
