<?php

declare(strict_types=1);

use App\Enums\OnboardingStep;
use App\Enums\OrganizationPermission;
use App\Enums\TransactionTier;
use App\Onboarding\OrganizationSlug;
use App\Onboarding\TierGuide;
use App\Support\Money;
use Illuminate\Config\Repository;

/* The signup wizard's fixed rules (ONBOARDING_COMPLIANCE.md § 2, § 5.2). */

function tierGuide(): TierGuide
{
    return new TierGuide(new Repository(['tiers' => require dirname(__DIR__, 3).'/config/tiers.php']));
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

it('puts the steps in order and maps each organization step to a permission', function () {
    expect(OnboardingStep::Account->number())->toBe(1)
        ->and(OnboardingStep::Data->number())->toBe(OnboardingStep::count())
        ->and(OnboardingStep::Tier->next())->toBe(OnboardingStep::Plan)
        ->and(OnboardingStep::Data->next())->toBeNull()
        ->and(OnboardingStep::Organization->previous())->toBe(OnboardingStep::VerifyEmail)
        ->and(OnboardingStep::Account->isOrganizationStep())->toBeFalse()
        ->and(OnboardingStep::Tier->permission())->toBe(OrganizationPermission::ManageCompliance)
        ->and(OnboardingStep::Plan->permission())->toBe(OrganizationPermission::ManageBilling)
        ->and(OnboardingStep::Collections->permission())->toBe(OrganizationPermission::ManagePaymentSettings);
});

it('makes a web address from a name', function (string $name, string $slug) {
    expect(OrganizationSlug::fromName($name))->toBe($slug);
})->with([
    ['Adeyemi Realty', 'adeyemi-realty'],
    ['  Bello & Sons Properties Ltd. ', 'bello-sons-properties-ltd'],
    ['Ọ̀yọ́ Homes', 'oyo-homes'],
    ['AB', 'ab-org'],
    [str_repeat('Long Name ', 10), 'long-name-long-name-long-name-long-name-long-nam'],
]);

it('accepts only well-formed, unreserved web addresses', function (string $slug, bool $ok) {
    expect(OrganizationSlug::isWellFormed($slug))->toBe($ok);
})->with([
    ['adeyemi-realty', true],
    ['abc', true],
    ['ab', false],
    ['Adeyemi', false],
    ['-ade', false],
    ['ade-', false],
    ['ade--realty', false],
    ['ade_realty', false],
    ['support', false],
    [str_repeat('a', 49), false],
]);
