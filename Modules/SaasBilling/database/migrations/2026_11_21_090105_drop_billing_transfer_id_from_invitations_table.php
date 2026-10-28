<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P02-04's billing transfers became the management handover (P03-14,
 * BILLING_PAYMENTS.md § 7.8): their invitations no longer point at a
 * transfer. A link still waiting for an answer is closed first (it stops
 * working, as when an offer is withdrawn); the rows stay, as every
 * invitation does. The handover's link uses `management_handover_id`
 * (Handovers module, 2026_11_21_090104).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invitations')
            ->where('kind', 'org_billing_transfer')
            ->whereNotNull('billing_transfer_id')
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('billing_transfer_id')->nullable()->after('target_organization_id')
                ->constrained('billing_transfer_requests')->restrictOnDelete();
        });
    }
};
