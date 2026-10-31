<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Transaction Tiers: wording (ONBOARDING_COMPLIANCE.md § 5.2)
|--------------------------------------------------------------------------
|
| The tiers' names, limits, review targets and required documents are data:
| versioned in the `transaction_tiers` table, changed in the console
| (Compliance → Tier settings) by compliance with a super-admin's approval,
| and read through App\Compliance\TransactionTiers (P06-08). Only whom each
| tier suits, as the signup wizard explains it (App\Onboarding\TierGuide),
| stays here: it limits nothing.
|
*/

return [

    'suits' => [
        1 => 'Individual agents and small landlords',
        2 => 'Registered agencies',
        3 => 'Large agencies, developers and estates',
    ],

];
