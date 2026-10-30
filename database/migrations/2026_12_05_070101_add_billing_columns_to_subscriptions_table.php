<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What metering needs on a subscription (P06-02; BILLING_PAYMENTS.md § 2–3,
 * DATABASE_SCHEMA.md § 2 `subscriptions`):
 *
 * - `payer_organization_id`: who pays, when not the organization itself
 *   (null). A payer that is platform-covered makes the invoices covered too
 *   (§ 2); its owners are told of each invoice. Set by staff (P06-07); no
 *   longer moved by management handovers (§ 7).
 * - `last_billed_units`: the units counted for the latest invoice.
 *
 * Written only by App\Subscriptions\SubscriptionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('payer_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->unsignedInteger('last_billed_units')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payer_organization_id');
            $table->dropColumn('last_billed_units');
        });
    }
};
