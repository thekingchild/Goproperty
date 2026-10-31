<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The launch values of ONBOARDING_COMPLIANCE.md § 5.2–5.3 (P06-08), as
 * config/tiers.php held them until now, as version 1 of each tier, approved
 * and in force from 1 January 2000 (before any organization). From here on
 * compliance proposes changes in the console (Compliance → Tier settings) and
 * a super-admin approves them. Kept literally in this migration so a later
 * edit never changes what a fresh install starts with. Skips a tier that
 * already has a version.
 */
return new class extends Migration
{
    /** @var list<array<string, mixed>> */
    private const array TIERS = [
        [
            'tier' => 1, 'name' => 'Standard',
            'annual_volume_limit_kobo' => 5_000_000_000, 'single_payment_limit_kobo' => 500_000_000, 'review_threshold_kobo' => null,
            'required_documents' => [], 'optional_documents' => [], 'review_sla_days' => null,
        ],
        [
            'tier' => 2, 'name' => 'Business',
            'annual_volume_limit_kobo' => 50_000_000_000, 'single_payment_limit_kobo' => 5_000_000_000, 'review_threshold_kobo' => null,
            'required_documents' => ['owner_id', 'cac_certificate', 'cac_status_report', 'scuml_certificate', 'proof_of_address', 'director_id'],
            'optional_documents' => ['state_regulator_registration', 'professional_membership'],
            'review_sla_days' => 2,
        ],
        [
            'tier' => 3, 'name' => 'Enterprise',
            'annual_volume_limit_kobo' => null, 'single_payment_limit_kobo' => null, 'review_threshold_kobo' => 10_000_000_000,
            'required_documents' => [
                'owner_id', 'cac_certificate', 'cac_status_report', 'scuml_certificate', 'proof_of_address', 'director_id',
                'beneficial_owners', 'aml_policy', 'state_regulator_registration',
            ],
            'optional_documents' => ['professional_membership'],
            'review_sla_days' => 5,
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::TIERS as $tier) {
            if (DB::table('transaction_tiers')->where('tier', $tier['tier'])->exists()) {
                continue;
            }

            DB::table('transaction_tiers')->insert([
                ...$tier,
                'version' => 1,
                'required_documents' => json_encode($tier['required_documents'], JSON_THROW_ON_ERROR),
                'optional_documents' => json_encode($tier['optional_documents'], JSON_THROW_ON_ERROR),
                'effective_from' => '2000-01-01',
                'status' => 'approved',
                'reason' => 'Launch values (ONBOARDING_COMPLIANCE.md § 5.2)',
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('transaction_tiers')->where('version', 1)->whereNull('proposed_by_staff_id')->delete();
    }
};
