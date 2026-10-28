<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Meter readings of a unit's metered utility (DATABASE_SCHEMA.md § 4
 * `utility_readings`, ARCHITECTURE.md § 6.4; P03-06a). Values are exact
 * decimals with three places (a water meter's litres), never floats:
 * `consumption` = `current_value` − `previous_value`. `previous_value` is
 * the last reading's current value (or the setting's starting reading),
 * or, when `meter_replaced`, the new meter's first value. `rate_kobo` is
 * the rate per unit of measure when the reading was taken (P03-06b bills
 * consumption × rate in kobo and links the reading to its bill).
 *
 * - Unique `(unit_utility_setting_id, reading_date, live)`: no two kept
 *   readings of a meter on the same day. `live` is 1 while the reading is
 *   kept and NULL once removed (NULLs never collide in a unique index on
 *   MySQL and MariaDB), so a reading removed by mistake does not block
 *   its date.
 * - Removing is a soft delete (`deleted_at`, who and why), allowed only
 *   for the latest reading not yet billed: its photo is evidence and
 *   stays (AGENT.md § 3 rule 7).
 *
 * Live, building-linked records: a management handover (P03-14) moves
 * them with their unit's setting. Written only by
 * Modules\Billing\Utilities\MeterReadings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_utility_setting_id')->constrained('unit_utility_settings')->restrictOnDelete();
            $table->date('reading_date');
            $table->decimal('previous_value', 15, 3);
            $table->decimal('current_value', 15, 3);
            $table->decimal('consumption', 15, 3);
            $table->boolean('meter_replaced')->default(false);
            $table->unsignedBigInteger('rate_kobo');
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->restrictOnDelete();
            $table->boolean('live')->nullable()->default(true);
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['unit_utility_setting_id', 'reading_date', 'live'], 'utility_readings_setting_date_unique');
            $table->index(['organization_id', 'unit_utility_setting_id', 'reading_date'], 'utility_readings_org_setting_date_index');
            $table->index(['organization_id', 'reading_date'], 'utility_readings_org_date_index');
            $table->index(['organization_id', 'bill_id'], 'utility_readings_org_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_readings');
    }
};
