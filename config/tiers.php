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
| `required_documents` / `optional_documents` (ONBOARDING_COMPLIANCE.md
| § 5.3): the App\Enums\ComplianceDocumentType values a Tier application
| must / may carry (App\Compliance\RequiredDocuments). Tier 3 also needs a
| named compliance contact. They move to `transaction_tiers.required_documents`
| with the Tier settings screen (P06-08). Tier 1 needs no application.
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
            'required_documents' => ['owner_id', 'cac_certificate', 'cac_status_report', 'scuml_certificate', 'proof_of_address', 'director_id'],
            'optional_documents' => ['state_regulator_registration', 'professional_membership'],
        ],
        3 => [
            'name' => 'Enterprise',
            'annual_volume_limit_kobo' => null,
            'single_payment_limit_kobo' => null,
            'suits' => 'Large agencies, developers and estates',
            'activation' => 'Compliance review and a call, about 5 business days',
            'required_documents' => [
                'owner_id', 'cac_certificate', 'cac_status_report', 'scuml_certificate', 'proof_of_address', 'director_id',
                'beneficial_owners', 'aml_policy', 'state_regulator_registration',
            ],
            'optional_documents' => ['professional_membership'],
        ],
    ],

];
