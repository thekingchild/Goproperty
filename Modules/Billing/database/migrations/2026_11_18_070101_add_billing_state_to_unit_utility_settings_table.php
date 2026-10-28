<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where a unit's utility stands in its billing (P03-06b; ARCHITECTURE.md
 * § 6.4 "How utility bills are built"), written only by
 * Modules\Billing\Bills\UtilityBillGenerator (and moved forward by
 * Utilities\UnitUtilitySettings when a switched-off utility is switched on
 * again):
 *
 * - `next_billing_date`: the first day of the next period to bill (null:
 *   from `effective_from`), as `leases.next_billing_date` is for rent.
 * - `billing_hold` / `billing_hold_since`: why the utility is not being
 *   billed (`no_tenant`: no lease of the unit covers the period;
 *   `no_reading`: a metered period ended without a meter reading — never
 *   estimated) and the first period start it applies to; cleared when a
 *   bill is issued. Shown on the units' utilities list.
 *
 * Live state of a building-linked record: a management handover (P03-14)
 * moves it with the setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_utility_settings', function (Blueprint $table) {
            $table->date('next_billing_date')->nullable()->after('starting_reading');
            $table->string('billing_hold', 20)->nullable()->after('next_billing_date');
            $table->date('billing_hold_since')->nullable()->after('billing_hold');
            $table->index(['organization_id', 'active', 'next_billing_date'], 'unit_utility_settings_org_active_next_index');
        });
    }

    public function down(): void
    {
        Schema::table('unit_utility_settings', function (Blueprint $table) {
            $table->dropIndex('unit_utility_settings_org_active_next_index');
            $table->dropColumn(['next_billing_date', 'billing_hold', 'billing_hold_since']);
        });
    }
};
