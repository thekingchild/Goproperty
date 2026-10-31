<?php

declare(strict_types=1);

use App\Enums\TransactionTier;
use App\Onboarding\TierGuide;
use App\Support\Money;

/*
 * The signup wizard's tier guide (ONBOARDING_COMPLIANCE.md § 5.2), from the
 * launch values seeded as version 1 of each tier (P06-08; moved here from the
 * unit tests when the limits moved from config/tiers.php to the database).
 */

function tierGuide(): TierGuide
{
    return app(TierGuide::class);
}

it('suggests the lowest tier whose yearly limit covers the stated collections', function (string $naira, TransactionTier $tier) {
    expect(tierGuide()->recommend(Money::naira($naira)))->toBe($tier);
})->with([
    ['0', TransactionTier::One],
    ['50,000,000', TransactionTier::One],
    ['50,000,000.01', TransactionTier::Two],
    ['500,000,000', TransactionTier::Two],
    ['500,000,001', TransactionTier::Three],
]);

it('shows the launch limits of each tier', function () {
    [$one, $two, $three] = tierGuide()->options();

    expect($one->annualLimit?->format())->toBe('₦50,000,000')
        ->and($one->singlePaymentLimit?->format())->toBe('₦5,000,000')
        ->and($one->needsReview())->toBeFalse()
        ->and($two->annualLimit?->format())->toBe('₦500,000,000')
        ->and($two->singlePaymentLimit?->format())->toBe('₦50,000,000')
        ->and($two->needsReview())->toBeTrue()
        ->and($three->annualLimit)->toBeNull()
        ->and($three->needsReview())->toBeTrue();
});

it('says how each tier is activated and whom it suits', function () {
    [$one, $two, $three] = tierGuide()->options();

    expect($one->activation)->toBe('Active straight away')
        ->and($two->activation)->toBe('Compliance review, about 2 business days')
        ->and($three->activation)->toBe('Compliance review and a call, about 5 business days')
        ->and($one->suits)->toBe('Individual agents and small landlords')
        ->and($three->name)->toBe('Enterprise');
});
