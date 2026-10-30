<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Subscription invoices (P06-02; BILLING_PAYMENTS.md § 1.4, § 3;
 * DATABASE_SCHEMA.md § 2 `platform_invoices`), `kind` subscription. The
 * terms and the count the invoice was worked out from are kept on it, so an
 * issued invoice explains itself after the plan changes:
 *
 * - `subscription_id`, `period_start` / `period_end` (Lagos dates): unique
 *   together, so a period is invoiced once however often metering runs.
 * - `plan_version_id`, `plan_name`, `billing_cycle`: the plan's version in
 *   effect on `period_start`.
 * - `units_billed` (every unit counted that day), `included_units`,
 *   `extra_units`, `base_price_kobo`, `unit_price_kobo` (null: custom):
 *   the month = base + extra units × unit price.
 * - `months_billed` (yearly: 10, "pay 10 months, get 12"), or `days_billed`
 *   of `days_in_month` for a first month that starts after the 1st.
 * - `vat_percent`: the VAT rate on the invoice ("7.5").
 *
 * SMS credit bundles (P05-03) leave them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('plan_name', 60)->nullable();
            $table->string('billing_cycle', 10)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('units_billed')->nullable();
            $table->unsignedInteger('included_units')->nullable();
            $table->unsignedInteger('extra_units')->nullable();
            $table->unsignedBigInteger('base_price_kobo')->nullable();
            $table->unsignedBigInteger('unit_price_kobo')->nullable();
            $table->unsignedTinyInteger('months_billed')->nullable();
            $table->unsignedTinyInteger('days_billed')->nullable();
            $table->unsignedTinyInteger('days_in_month')->nullable();
            $table->string('vat_percent', 6)->nullable();

            $table->unique(['subscription_id', 'period_start'], 'platform_invoices_subscription_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropUnique('platform_invoices_subscription_period_unique');
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropConstrainedForeignId('plan_version_id');
            $table->dropColumn(['plan_name', 'billing_cycle', 'period_start', 'period_end', 'units_billed', 'included_units', 'extra_units', 'base_price_kobo', 'unit_price_kobo', 'months_billed', 'days_billed', 'days_in_month', 'vat_percent']);
        });
    }
};
