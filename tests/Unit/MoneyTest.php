<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Support\Money;

/*
 * P00-07 acceptance: the money helper (AGENT.md § 3 rule 2). Integer kobo,
 * no floats, exact parsing, overflow refused, rounding explicit, and
 * allocations that always add up.
 */

it('holds integer kobo', function () {
    $money = Money::kobo(125_000_050);

    expect($money->kobo)->toBe(125_000_050)
        ->and($money->currency())->toBe(Currency::NGN)
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('converts whole naira', function () {
    expect(Money::naira(1_250_000)->kobo)->toBe(125_000_000)
        ->and(Money::naira(-5)->kobo)->toBe(-500);
});

it('parses naira strings exactly', function (string $input, int $kobo) {
    expect(Money::naira($input)->kobo)->toBe($kobo);
})->with([
    ['0', 0],
    ['1250000', 125_000_000],
    ['1,250,000', 125_000_000],
    ['1,250,000.50', 125_000_050],
    ['₦1,250,000.5', 125_000_050],
    ['₦ 75.05', 7_505],
    ['-500', -50_000],
    ['-₦0.01', -1],
    ['  42.10  ', 4_210],
    ['0007', 700],
    // 0.1 + 0.2 style float errors cannot happen: no floats are involved.
    ['0.29', 29],
    ['1234567890123456.99', 123_456_789_012_345_699],
]);

it('refuses anything that is not an exact naira amount', function (string $input) {
    Money::naira($input);
})->throws(InvalidArgumentException::class)->with([
    '', 'abc', '1.234', '1,25,000', '1,250,0', '12,', '1.', '.5', '1e5', '1 000', '--5', '5-', '₦₦5', '0x1A',
]);

it('refuses amounts that overflow', function (Closure $operation) {
    $operation();
})->throws(OverflowException::class)->with([
    'parse' => [fn () => Money::naira('99999999999999999')],
    'whole naira' => [fn () => Money::naira(PHP_INT_MAX)],
    'add' => [fn () => Money::kobo(PHP_INT_MAX)->plus(Money::kobo(1))],
    'subtract' => [fn () => Money::kobo(-PHP_INT_MAX)->minus(Money::kobo(1))],
    'multiply' => [fn () => Money::kobo(PHP_INT_MAX)->times(2)],
    'percentage' => [fn () => Money::kobo(PHP_INT_MAX)->percentage(10_000)],
    'minimum integer' => [fn () => Money::kobo(PHP_INT_MIN)],
]);

it('adds, subtracts and multiplies', function () {
    $rent = Money::naira(1_200_000);
    $service = Money::naira('150,000.75');

    expect($rent->plus($service)->kobo)->toBe(135_000_075)
        ->and($rent->minus($service)->kobo)->toBe(104_999_925)
        ->and($service->times(3)->kobo)->toBe(45_000_225)
        ->and($service->times(-1)->kobo)->toBe(-15_000_075)
        ->and($rent->negate()->abs()->equals($rent))->toBeTrue();
});

it('never changes the original', function () {
    $amount = Money::kobo(100);
    $amount->plus(Money::kobo(50));

    expect($amount->kobo)->toBe(100);
});

it('takes percentages in basis points with explicit rounding', function (int $kobo, int $basisPoints, RoundingMode $mode, int $expected) {
    expect(Money::kobo($kobo)->percentage($basisPoints, $mode)->kobo)->toBe($expected);
})->with([
    '7.5% of ₦1,000' => [100_000, 750, RoundingMode::HalfAwayFromZero, 7_500],
    '10% of 5 kobo, half up' => [5, 1_000, RoundingMode::HalfAwayFromZero, 1],
    '10% of 5 kobo, half even' => [5, 1_000, RoundingMode::HalfEven, 0],
    '10% of 15 kobo, half even' => [15, 1_000, RoundingMode::HalfEven, 2],
    '10% of 5 kobo, half towards zero' => [5, 1_000, RoundingMode::HalfTowardsZero, 0],
    '10% of 5 kobo, half odd' => [5, 1_000, RoundingMode::HalfOdd, 1],
    '1% of 99 kobo, towards zero' => [99, 100, RoundingMode::TowardsZero, 0],
    '1% of 99 kobo, away from zero' => [99, 100, RoundingMode::AwayFromZero, 1],
    '1% of 1 kobo, up' => [1, 100, RoundingMode::PositiveInfinity, 1],
    '1% of 1 kobo, down' => [1, 100, RoundingMode::NegativeInfinity, 0],
    'negative, half away' => [-5, 1_000, RoundingMode::HalfAwayFromZero, -1],
    'negative, down' => [-1, 100, RoundingMode::NegativeInfinity, -1],
    'negative, up' => [-1, 100, RoundingMode::PositiveInfinity, 0],
    'exact' => [123_400, 5_000, RoundingMode::HalfEven, 61_700],
    'over 100%' => [10_000, 15_000, RoundingMode::HalfAwayFromZero, 15_000],
]);

it('divides by whole numbers', function () {
    expect(Money::kobo(100)->dividedBy(3)->kobo)->toBe(33)
        ->and(Money::kobo(200)->dividedBy(3)->kobo)->toBe(67)
        ->and(Money::kobo(100)->dividedBy(-3)->kobo)->toBe(-33)
        ->and(Money::kobo(5)->dividedBy(2, RoundingMode::HalfEven)->kobo)->toBe(2)
        ->and(fn () => Money::kobo(1)->dividedBy(0))->toThrow(DivisionByZeroError::class);
});

it('allocates without losing a kobo', function (int $kobo, array $ratios, array $expected) {
    $parts = Money::kobo($kobo)->allocate(...$ratios);

    expect(array_map(fn (Money $part): int => $part->kobo, $parts))->toBe($expected)
        ->and(Money::sum($parts)->kobo)->toBe($kobo);
})->with([
    'thirds' => [100, [1, 1, 1], [34, 33, 33]],
    'largest remainder wins' => [100, [1, 2], [33, 67]],
    '70/30 split' => [1_000_001, [70, 30], [700_001, 300_000]],
    'zero ratio' => [500, [0, 1], [0, 500]],
    'negative' => [-100, [1, 1, 1], [-34, -33, -33]],
    'nothing to share' => [0, [1, 1], [0, 0]],
    'one part' => [999, [5], [999]],
]);

it('splits into equal parts', function () {
    expect(array_map(fn (Money $part): int => $part->kobo, Money::kobo(1_000)->split(3)))->toBe([334, 333, 333])
        ->and(fn () => Money::kobo(1)->split(0))->toThrow(InvalidArgumentException::class);
});

it('refuses ratios that cannot share', function (array $ratios) {
    Money::kobo(100)->allocate(...$ratios);
})->throws(InvalidArgumentException::class)->with([[[]], [[0, 0]], [[1, -1]]]);

it('compares', function () {
    $small = Money::kobo(100);
    $large = Money::kobo(200);

    expect($small->lessThan($large))->toBeTrue()
        ->and($small->lessThanOrEqual(Money::kobo(100)))->toBeTrue()
        ->and($large->greaterThan($small))->toBeTrue()
        ->and($large->greaterThanOrEqual($large))->toBeTrue()
        ->and($small->compareTo($large))->toBe(-1)
        ->and($large->compareTo($small))->toBe(1)
        ->and($small->compareTo(Money::kobo(100)))->toBe(0)
        ->and($small->equals(Money::kobo(100)))->toBeTrue()
        ->and(Money::kobo(-1)->isNegative())->toBeTrue()
        ->and(Money::kobo(1)->isPositive())->toBeTrue()
        ->and(Money::min($large, $small, Money::kobo(150))->kobo)->toBe(100)
        ->and(Money::max($small, $large, Money::kobo(150))->kobo)->toBe(200);
});

it('sums', function () {
    expect(Money::sum([Money::kobo(1), Money::kobo(2), Money::kobo(-5)])->kobo)->toBe(-2)
        ->and(Money::sum([])->isZero())->toBeTrue();
});

it('formats for people', function (int $kobo, string $formatted) {
    expect(Money::kobo($kobo)->format())->toBe($formatted)
        ->and((string) Money::kobo($kobo))->toBe($formatted);
})->with([
    [125_000_000, '₦1,250,000'],
    [125_000_050, '₦1,250,000.50'],
    [5, '₦0.05'],
    [0, '₦0'],
    [99_900, '₦999'],
    [100_000, '₦1,000'],
    [-50_000, '-₦500'],
    [-1, '-₦0.01'],
    [PHP_INT_MAX, '₦92,233,720,368,547,758.07'],
]);

it('writes plain decimals for exports and payment providers', function (int $kobo, string $decimal) {
    expect(Money::kobo($kobo)->toDecimalString())->toBe($decimal);
})->with([[125_000_050, '1250000.50'], [5, '0.05'], [0, '0.00'], [-1_000, '-10.00']]);

it('serialises to JSON as kobo', function () {
    expect(json_encode(['amount' => Money::kobo(12_345)]))->toBe('{"amount":12345}');
});

it('round-trips every formatted amount', function (int $kobo) {
    expect(Money::naira(Money::kobo($kobo)->format())->kobo)->toBe($kobo)
        ->and(Money::naira(Money::kobo($kobo)->toDecimalString())->kobo)->toBe($kobo);
})->with([0, 1, 99, 100, 101, 123_456_789, -42_00, -1]);
