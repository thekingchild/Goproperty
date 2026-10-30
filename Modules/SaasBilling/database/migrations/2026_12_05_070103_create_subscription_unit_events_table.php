<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The metering ledger (P06-02; BILLING_PAYMENTS.md § 3 step 2,
 * DATABASE_SCHEMA.md § 2 `subscription_unit_events`): one row for each
 * billing period, the units counted for its invoice, kept for disputes and
 * analytics. Organization-scoped; written only by
 * Modules\SaasBilling\Metering\SubscriptionMetering; never changed or
 * deleted.
 *
 * - `effective_date`: the period's first day (unique per subscription, as
 *   the invoice is).
 * - `unit_count` (every unit counted), `included_units`, `counted_units`
 *   (over the included ones: the units paid for).
 * - `reason`: `initial` (first count), `plan_change` (another plan or
 *   version than the last row), `unit_added`, `unit_removed`, `unchanged`.
 * - `platform_invoice_id`: the invoice it made (the `source_bill_id` of the
 *   first schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_unit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('effective_date');
            $table->unsignedInteger('unit_count');
            $table->unsignedInteger('included_units');
            $table->unsignedInteger('counted_units');
            $table->string('reason', 20);
            $table->timestamps();

            $table->unique(['subscription_id', 'effective_date'], 'subscription_unit_events_period_unique');
            $table->index(['organization_id', 'effective_date'], 'subscription_unit_events_org_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_unit_events');
    }
};
