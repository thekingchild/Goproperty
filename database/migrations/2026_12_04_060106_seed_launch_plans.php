<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The launch plans of BILLING_PAYMENTS.md § 1.1–1.2 (P06-01a), as
 * config/plans.php held them until now, as version 1 of each plan in effect
 * from 1 January 2026. From here on finance-admin edits them in the console
 * (Billing → Plans). Kept literally in this migration so a later edit never
 * changes what a fresh install starts with. Skips a plan whose key exists.
 */
return new class extends Migration
{
    /** @var list<array<string, mixed>> */
    private const array PLANS = [
        [
            'key' => 'starter', 'name' => 'Starter', 'audience' => 'Independent agents and small landlords',
            'highlights' => ['Properties, tenants, leases, bills', 'Branded receipts & statements', 'Bank statement matching', '3 staff · 100 SMS a month'],
            'featured' => false, 'custom' => false, 'base' => 1_000_000, 'included' => 10, 'unit' => 70_000,
            'switches' => [], 'staff_users' => 3, 'sms_credits' => 100,
        ],
        [
            'key' => 'growth', 'name' => 'Growth', 'audience' => 'Growing agencies with online payments',
            'highlights' => ['Everything in Starter', 'Your own Paystack for online payments', 'WhatsApp notifications · Building Group', '10 staff · 500 SMS a month'],
            'featured' => true, 'custom' => false, 'base' => 3_500_000, 'included' => 50, 'unit' => 50_000,
            'switches' => ['own_paystack', 'whatsapp', 'building_group'], 'staff_users' => 10, 'sms_credits' => 500,
        ],
        [
            'key' => 'scale', 'name' => 'Scale', 'audience' => 'Large portfolios and multi-team agencies',
            'highlights' => ['Everything in Growth', 'Approvals and agent-to-building assignment', 'Remove "Generated with :brand"', 'Unlimited staff · assisted import'],
            'featured' => false, 'custom' => false, 'base' => 8_500_000, 'included' => 200, 'unit' => 35_000,
            'switches' => ['own_paystack', 'whatsapp', 'building_group', 'agent_assignment', 'approval_workflows', 'remove_powered_by', 'assisted_import'],
            'staff_users' => null, 'sms_credits' => 2_000,
        ],
        [
            'key' => 'enterprise', 'name' => 'Enterprise', 'audience' => 'Estates and portfolios of 1,000+ units',
            'highlights' => ['Everything in Scale', 'Dedicated account manager', 'Custom SMS volume'],
            'featured' => false, 'custom' => true, 'base' => 25_000_000, 'included' => 1_000, 'unit' => null,
            'switches' => ['own_paystack', 'whatsapp', 'building_group', 'agent_assignment', 'approval_workflows', 'remove_powered_by', 'assisted_import'],
            'staff_users' => null, 'sms_credits' => 2_000,
        ],
    ];

    private const array SWITCHES = ['own_paystack', 'whatsapp', 'building_group', 'agent_assignment', 'approval_workflows', 'remove_powered_by', 'assisted_import'];

    public function up(): void
    {
        $now = now();

        foreach (self::PLANS as $order => $plan) {
            if (DB::table('plans')->where('key', $plan['key'])->exists()) {
                continue;
            }

            $planId = DB::table('plans')->insertGetId([
                'key' => $plan['key'],
                'name' => $plan['name'],
                'audience' => $plan['audience'],
                'highlights' => json_encode($plan['highlights'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'featured' => $plan['featured'],
                'custom' => $plan['custom'],
                'trial_days' => 14,
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $versionId = DB::table('plan_versions')->insertGetId([
                'plan_id' => $planId,
                'version' => 1,
                'base_price_kobo' => $plan['base'],
                'included_units' => $plan['included'],
                'unit_price_kobo' => $plan['unit'],
                'effective_from' => '2026-01-01',
                'reason' => 'Launch prices (BILLING_PAYMENTS.md § 1.1)',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = [];

            foreach (self::SWITCHES as $key) {
                $rows[] = ['feature_key' => $key, 'enabled' => in_array($key, $plan['switches'], true), 'limit_value' => null];
            }

            $rows[] = ['feature_key' => 'staff_users', 'enabled' => true, 'limit_value' => $plan['staff_users']];
            $rows[] = ['feature_key' => 'sms_credits', 'enabled' => true, 'limit_value' => $plan['sms_credits']];

            DB::table('plan_feature')->insert(array_map(static fn (array $row): array => $row + [
                'plan_version_id' => $versionId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows));
        }
    }

    public function down(): void
    {
        $plans = DB::table('plans')->whereIn('key', array_column(self::PLANS, 'key'))->pluck('id');
        $versions = DB::table('plan_versions')->whereIn('plan_id', $plans)->pluck('id');

        DB::table('plan_feature')->whereIn('plan_version_id', $versions)->delete();
        DB::table('plan_versions')->whereIn('id', $versions)->delete();
        DB::table('plans')->whereIn('id', $plans)->delete();
    }
};
