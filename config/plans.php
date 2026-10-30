<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Subscription plans (BILLING_PAYMENTS.md § 1)
|--------------------------------------------------------------------------
|
| Launch prices, in kobo, read by the landing page (App\Marketing\PlanCatalogue).
| The SaaS billing module replaces this file with the plans table, editable
| in the console (BILLING_PAYMENTS.md § 1.3); the landing page then reads
| that table. Prices are never typed into website settings.
|
*/

return [

    'vat_percent' => '7.5',

    // Annual billing: pay 10 months, get 12.
    'months_paid_per_year' => 10,

    'trial_days' => 14,

    'plans' => [
        [
            'key' => 'starter',
            // SMS credits included each month (§ 1.2; P05-03).
            'sms_credits' => 100,
            'name' => 'Starter',
            'audience' => 'Independent agents and small landlords',
            'base_kobo' => 1_000_000,
            'included_units' => 10,
            'extra_unit_kobo' => 70_000,
            'features' => ['Properties, tenants, leases, bills', 'Branded receipts & statements', 'Bank statement matching', '3 staff · 100 SMS a month'],
        ],
        [
            'key' => 'growth',
            // SMS credits included each month (§ 1.2; P05-03).
            'sms_credits' => 500,
            'name' => 'Growth',
            'audience' => 'Growing agencies with online payments',
            'base_kobo' => 3_500_000,
            'included_units' => 50,
            'extra_unit_kobo' => 50_000,
            'featured' => true,
            'features' => ['Everything in Starter', 'Your own Paystack for online payments', 'WhatsApp notifications · Building Group', '10 staff · 500 SMS a month'],
        ],
        [
            'key' => 'scale',
            // SMS credits included each month (§ 1.2; P05-03).
            'sms_credits' => 2_000,
            'name' => 'Scale',
            'audience' => 'Large portfolios and multi-team agencies',
            'base_kobo' => 8_500_000,
            'included_units' => 200,
            'extra_unit_kobo' => 35_000,
            'features' => ['Everything in Growth', 'Approvals and agent-to-building assignment', 'Remove "Generated with :brand"', 'Unlimited staff · assisted import'],
        ],
        [
            'key' => 'enterprise',
            // SMS credits included each month (§ 1.2; P05-03). Enterprise is custom: finance-admin sets
            // each customer's allowance in the console; this is the default.
            'sms_credits' => 2_000,
            'name' => 'Enterprise',
            'audience' => 'Estates and portfolios of 1,000+ units',
            'base_kobo' => 25_000_000,
            'included_units' => 1000,
            'extra_unit_kobo' => null,
            'custom' => true,
            'features' => ['Everything in Scale', 'Dedicated account manager', 'Custom SMS volume'],
        ],
    ],

];
