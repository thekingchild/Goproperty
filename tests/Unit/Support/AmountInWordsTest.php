<?php

declare(strict_types=1);

use App\Support\AmountInWords;
use App\Support\Money;

/*
 * P03-05a acceptance: amount in words (BRANDING_DOCUMENTS.md § 3 item 2,
 * § 5: "tested for kobo, millions and billions"), from integer kobo.
 */

describe('acceptance: amount in words for kobo, millions and billions', function () {
    it('writes kobo', function (int $kobo, string $words) {
        expect(Money::kobo($kobo)->inWords())->toBe($words);
    })->with([
        'one kobo' => [1, 'One kobo only'],
        'fifty kobo' => [50, 'Fifty kobo only'],
        'ninety-nine kobo' => [99, 'Ninety-nine kobo only'],
        'naira and kobo' => [125_050, 'One thousand, two hundred and fifty naira and fifty kobo only'],
        'naira and one kobo' => [100_001, 'One thousand naira and one kobo only'],
        'teen kobo' => [2_000_013, 'Twenty thousand naira and thirteen kobo only'],
    ]);

    it('writes millions', function (int $kobo, string $words) {
        expect(Money::kobo($kobo)->inWords())->toBe($words);
    })->with([
        'the sample receipt' => [290_000_000, 'Two million, nine hundred thousand naira only'],
        'one million' => [100_000_000, 'One million naira only'],
        'every group' => [123_456_789_00, 'One hundred and twenty-three million, four hundred and fifty-six thousand, seven hundred and eighty-nine naira only'],
        'million and a little' => [100_000_500, 'One million and five naira only'],
        'million with kobo' => [350_000_075, 'Three million, five hundred thousand naira and seventy-five kobo only'],
    ]);

    it('writes billions', function (int $kobo, string $words) {
        expect(Money::kobo($kobo)->inWords())->toBe($words);
    })->with([
        'one billion' => [100_000_000_000, 'One billion naira only'],
        'billions and millions' => [2_550_000_000_000, 'Twenty-five billion, five hundred million naira only'],
        'every group and kobo' => [1_002_003_004_05, 'One billion, two million, three thousand and four naira and five kobo only'],
        'hundreds of billions' => [999_999_999_999_99, 'Nine hundred and ninety-nine billion, nine hundred and ninety-nine million, nine hundred and ninety-nine thousand, nine hundred and ninety-nine naira and ninety-nine kobo only'],
    ]);

    it('writes zero and exact naira', function (int $kobo, string $words) {
        expect(Money::kobo($kobo)->inWords())->toBe($words);
    })->with([
        'zero' => [0, 'Zero naira only'],
        'one naira' => [100, 'One naira only'],
        'eleven naira' => [1_100, 'Eleven naira only'],
        'a hundred naira' => [10_000, 'One hundred naira only'],
        'a hundred and one naira' => [10_100, 'One hundred and one naira only'],
        'thousand and fifty' => [105_000, 'One thousand and fifty naira only'],
        'thousand, one hundred' => [110_000, 'One thousand, one hundred naira only'],
    ]);
});

it('goes past billions to the largest amount Money holds', function () {
    expect(Money::kobo(2_500_000_000_000_00)->inWords())->toBe('Two trillion, five hundred billion naira only')
        ->and(Money::kobo(PHP_INT_MAX)->inWords())->toStartWith('Ninety-two quadrillion, two hundred and thirty-three trillion,')
        ->and(Money::kobo(PHP_INT_MAX)->inWords())->toEndWith('naira and seven kobo only');
});

it('says minus for a negative amount', function () {
    expect(Money::kobo(-50_000)->inWords())->toBe('Minus five hundred naira only')
        ->and(Money::kobo(-1)->inWords())->toBe('Minus one kobo only');
});

it('writes whole numbers on their own', function () {
    expect(AmountInWords::number(0))->toBe('zero')
        ->and(AmountInWords::number(21))->toBe('twenty-one')
        ->and(AmountInWords::number(1_000_000))->toBe('one million')
        ->and(AmountInWords::number(1_000_001))->toBe('one million and one');
});
