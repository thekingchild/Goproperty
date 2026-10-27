<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Units: flats, self-contains, shops and offices in a building
 * (DATABASE_SCHEMA.md § 3 `units`). The label is unique in its building,
 * archived (soft-deleted) units included: adding a unit with an archived
 * unit's label brings that unit back. Rent and service charge are integer
 * kobo (AGENT.md § 3 rule 2), the yearly asking amounts a lease starts
 * from. `status` is read by the status board and occupancy counts
 * (ARCHITECTURE.md § 6.2). Archiving a unit is a soft delete: the GoProperty
 * bill counts every unit that is not soft-deleted and whose building is not
 * archived (BILLING_PAYMENTS.md § 1.4). Import provenance (§ 8A):
 * `import_batch_id` has no foreign key (import_batches belongs to the
 * Import module) and `external_ref` is unique per organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('unit_label', 60);
            $table->string('unit_type', 24);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedInteger('size_sqm')->nullable();
            $table->string('floor', 20)->nullable();
            $table->unsignedBigInteger('base_rent_kobo')->default(0);
            $table->unsignedBigInteger('service_charge_kobo')->default(0);
            $table->string('status', 24)->default('vacant');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'unit_label'], 'units_property_label_unique');
            $table->unique(['organization_id', 'external_ref'], 'units_org_external_ref_unique');
            $table->index(['organization_id', 'import_batch_id'], 'units_org_import_batch_index');
            $table->index(['organization_id', 'property_id', 'status'], 'units_org_property_status_index');
            $table->index(['organization_id', 'status'], 'units_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
