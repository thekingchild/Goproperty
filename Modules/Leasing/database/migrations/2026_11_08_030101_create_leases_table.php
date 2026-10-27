<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Leases (DATABASE_SCHEMA.md § 3 `leases`, ARCHITECTURE.md § 6.3): the
 * allocation of a unit to a tenant and its contract. Written only by
 * Modules\Leasing\Leases\LeaseService (AGENT.md § 3 rule 5).
 *
 * `property_id` is the unit's building, kept on the lease so agent
 * narrowing (Contracts\BuildingAccess) and a management handover
 * (P03-14: "move these buildings' live records") find a building's leases
 * without joining the Properties module's tables (ARCHITECTURE.md § 8 rule
 * 3); a unit never changes building. `contract_number` is given at
 * activation by App\Documents\DocumentNumberer (unique per organization;
 * drafts have none). `reserved_until` is the last day a draft holds its
 * unit (the organization's `leasing.reservation_days`, default 14);
 * `next_billing_date` is the next period start to bill, worked out by
 * Modules\Leasing\CycleCalculator. Money is integer kobo (AGENT.md § 3
 * rule 2). Import provenance (§ 8A): `import_batch_id` has no foreign key
 * (import_batches belongs to the Import module), `external_ref` is unique
 * per organization. Soft deletes are left to import rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->string('contract_number', 40)->nullable();
            $table->string('billing_cycle', 16);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('rent_amount_kobo');
            $table->unsignedBigInteger('deposit_kobo')->default(0);
            $table->date('paid_until')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->json('other_charges')->nullable();
            $table->unsignedSmallInteger('due_day_offset')->default(0);
            $table->boolean('auto_renew')->default(false);
            $table->string('status', 16)->default('draft');
            $table->foreignId('renewal_of_lease_id')->nullable()->constrained('leases')->restrictOnDelete();
            $table->date('reserved_until')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->timestamp('ending_soon_flagged_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('document_media_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'contract_number'], 'leases_org_contract_number_unique');
            $table->unique(['organization_id', 'external_ref'], 'leases_org_external_ref_unique');
            $table->index(['organization_id', 'status'], 'leases_org_status_index');
            $table->index(['unit_id', 'status'], 'leases_unit_status_index');
            $table->index(['organization_id', 'property_id', 'status'], 'leases_org_property_status_index');
            $table->index(['organization_id', 'tenant_profile_id'], 'leases_org_tenant_index');
            $table->index(['organization_id', 'status', 'end_date'], 'leases_org_status_end_index');
            $table->index(['organization_id', 'status', 'next_billing_date'], 'leases_org_status_next_billing_index');
            $table->index(['organization_id', 'import_batch_id'], 'leases_org_import_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
