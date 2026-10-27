<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bills to tenants (DATABASE_SCHEMA.md § 4 `bills`, ARCHITECTURE.md § 6.4):
 * rent bills generated from leases (P03-04b), later utility, opening
 * balance and other bills. Written only by the Billing module
 * (Bills\BillService, Bills\BillIssuer, Bills\Ledger). Money is integer
 * kobo (AGENT.md § 3 rule 2).
 *
 * - `payment_reference`: short code for bank transfer narrations
 *   ("GP-7KQ2M9X"), unique across every organization.
 * - `number`: the invoice number from App\Documents\DocumentNumberer
 *   (type `invoice`), unique per organization.
 * - `(lease_id, type, period_start)` is the natural key of a generated
 *   bill: unique, so a re-run (or two runs at once) of bill generation
 *   cannot bill a lease's period twice (AGENT.md § 3 rule 6). Bills without
 *   a lease (lease_id null) are not constrained by it.
 * - `paid_kobo`: what payments and credit have settled so far, kept by the
 *   bill ledger (Contracts\BillLedger) under the bill's row lock; the
 *   outstanding amount is `amount_kobo - paid_kobo`.
 * - `property_id` / `unit_id`: the building and unit billed, for agent
 *   narrowing (Contracts\BuildingAccess) without joining the Properties
 *   module's tables (ARCHITECTURE.md § 8 rule 3).
 * - `branding_snapshot` / `verification_code`: the organization's branding
 *   and the verification code as at issue (BRANDING_DOCUMENTS.md § 5).
 * - Import provenance (§ 8A): `import_batch_id` without a foreign key,
 *   `external_ref` unique per organization.
 *
 * History: bills stay with the organization that issued them, also when
 * their building is handed over (P03-14). Never deleted: void instead
 * (AGENT.md § 3 rule 7); soft deletes are left to import rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('billable_type', 20);
            $table->unsignedBigInteger('billable_id');
            $table->foreignId('lease_id')->nullable()->constrained('leases')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('payment_reference', 16);
            $table->string('number', 40);
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('paid_kobo')->default(0);
            $table->date('due_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 20)->default('unpaid');
            // An explicit default: without one, MariaDB 10.6 (explicit_defaults_for_timestamp
            // off) makes the first NOT NULL TIMESTAMP update itself on every change.
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedBigInteger('document_media_id')->nullable();
            $table->json('branding_snapshot');
            $table->char('verification_code', 10);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('payment_reference', 'bills_payment_reference_unique');
            $table->unique(['organization_id', 'number'], 'bills_org_number_unique');
            $table->unique('verification_code', 'bills_verification_code_unique');
            $table->unique(['lease_id', 'type', 'period_start'], 'bills_lease_type_period_unique');
            $table->unique(['organization_id', 'external_ref'], 'bills_org_external_ref_unique');
            $table->index(['organization_id', 'status', 'due_date'], 'bills_org_status_due_index');
            $table->index(['billable_type', 'billable_id'], 'bills_billable_index');
            $table->index(['organization_id', 'type', 'status'], 'bills_org_type_status_index');
            $table->index(['organization_id', 'property_id', 'status'], 'bills_org_property_status_index');
            $table->index(['organization_id', 'tenant_profile_id', 'status'], 'bills_org_tenant_status_index');
            $table->index(['organization_id', 'due_date'], 'bills_org_due_index');
            $table->index(['organization_id', 'import_batch_id'], 'bills_org_import_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
