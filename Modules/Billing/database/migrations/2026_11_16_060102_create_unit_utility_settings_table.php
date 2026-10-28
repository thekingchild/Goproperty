<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which utilities a unit is charged (DATABASE_SCHEMA.md § 4
 * `unit_utility_settings`, ARCHITECTURE.md § 6.4; P03-06a). A unit is
 * charged a utility only while it has an active row here; `amount_kobo`
 * and `cycle` override the type's defaults when set (null: the type's).
 * `starting_reading` is a metered utility's meter reading on
 * `effective_from` (the first reading's previous value; null = 0).
 *
 * - Unique `(unit_id, utility_type_id)`: one setting per unit and utility;
 *   switching a utility off keeps the row (`active` false).
 * - `property_id`: the unit's building, kept here so agent narrowing
 *   (Modules\Properties\Contracts\BuildingAccess) needs no join into the
 *   Properties module's tables (ARCHITECTURE.md § 8 rule 3).
 * - Import provenance (§ 8A): `import_batch_id` without a foreign key,
 *   `external_ref` unique per organization (DATA_IMPORT.md § 1 row 7).
 *
 * Live, building-linked records: a management handover (P03-14) moves
 * them with their building (BILLING_PAYMENTS.md § 7). Written only by
 * Modules\Billing\Utilities\UnitUtilitySettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_utility_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('utility_type_id')->constrained('utility_types')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo')->nullable();
            $table->string('cycle', 16)->nullable();
            $table->boolean('active')->default(true);
            $table->date('effective_from');
            $table->decimal('starting_reading', 15, 3)->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['unit_id', 'utility_type_id'], 'unit_utility_settings_unit_type_unique');
            $table->unique(['organization_id', 'external_ref'], 'unit_utility_settings_org_external_ref_unique');
            $table->index(['organization_id', 'property_id'], 'unit_utility_settings_org_property_index');
            $table->index(['organization_id', 'utility_type_id'], 'unit_utility_settings_org_type_index');
            $table->index(['organization_id', 'active'], 'unit_utility_settings_org_active_index');
            $table->index(['organization_id', 'import_batch_id'], 'unit_utility_settings_org_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_utility_settings');
    }
};
