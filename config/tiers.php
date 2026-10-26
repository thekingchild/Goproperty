<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Transaction Tier limits (ONBOARDING_COMPLIANCE.md § 5.2)
|--------------------------------------------------------------------------
|
| Launch values, in kobo, shown in the signup wizard's Tier step to explain
| the tiers and suggest one (App\Onboarding\TierGuide). Null means no cap.
| The compliance module replaces this file with the versioned
| `transaction_tiers` table, edited in the console under a two-person rule;
| nothing here enforces a limit.
|
*/

return [

    'tiers' => [
        1 => [
            'name' => 'Standard',
            'annual_volume_limit_kobo' => 5_000_000_000,   // ₦50 million a year
            'single_payment_limit_kobo' => 500_000_000,    // ₦5 million
            'suits' => 'Individual agents and small landlords',
            'activation' => 'Active straight away',
        ],
        2 => [
            'name' => 'Business',
            'annual_volume_limit_kobo' => 50_000_000_000,  // ₦500 million a year
            'single_payment_limit_kobo' => 5_000_000_000,  // ₦50 million
            'suits' => 'Registered agencies',
            'activation' => 'Compliance review, about 2 business days',
        ],
        3 => [
            'name' => 'Enterprise',
            'annual_volume_limit_kobo' => null,
            'single_payment_limit_kobo' => null,
            'suits' => 'Large agencies, developers and estates',
            'activation' => 'Compliance review and a call, about 5 business days',
        ],
    ],

];
