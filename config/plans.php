<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Subscription plans (BILLING_PAYMENTS.md § 1)
|--------------------------------------------------------------------------
|
| The plans, their prices and features are in the `plans`, `plan_versions`
| and `plan_feature` tables since P06-01a, edited by finance-admin in the
| console (BILLING_PAYMENTS.md § 1.3) and read through
| App\Marketing\PlanCatalogue. What stays here applies to every plan.
|
*/

return [

    'vat_percent' => '7.5',

    // Annual billing: pay 10 months, get 12.
    'months_paid_per_year' => 10,

    // Used only when no plan is offered at all; each plan has its own trial days.
    'trial_days' => 14,

];
