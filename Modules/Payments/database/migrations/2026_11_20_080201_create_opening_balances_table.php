<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Opening balances brought over by an import (P03-13b; DATA_IMPORT.md § 1
 * row 6, § 3; DATABASE_SCHEMA.md § 4 `opening_balances`): one row per row
 * of the Opening balances sheet — what a tenant owed (or had in credit) on
 * the as-of date. An amount owed became ONE bill of type `opening_balance`
 * (`bill_id`, issued by the Billing module through its contract); a credit
 * became a tenant credit entry (`credit_entry_id`), applied to the
 * tenant's next bill.
 *
 * - `amount_kobo` is signed: positive = owed, negative = credit.
 * - `property_id` / `unit_id` / `lease_id`: the tenancy it belongs to (a
 *   bill needs the unit; a credit may name none).
 * - Import provenance (§ 8A): `import_batch_id` without a foreign key,
 *   `external_ref` unique per organization. Soft deleted by the import's
 *   rollback (the bill is withdrawn, the credit reversed by an entry); a
 *   later import of the same balance restores it.
 *
 * Written only by Modules\Payments\Import\OpeningBalancesImportTarget
 * through Modules\Payments\Credit\TenantCredit and Billing's
 * Contracts\OpeningBalanceBills. History of the organization that imported
 * it: never moved by a management handover (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained('leases')->restrictOnDelete();
            $table->date('as_of_date');
            $table->bigInteger('amount_kobo');
            $table->string('description', 255)->nullable();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->restrictOnDelete();
            $table->foreignId('credit_entry_id')->nullable()->constrained('tenant_credit_entries')->restrictOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'external_ref'], 'opening_balances_org_external_ref_unique');
            $table->index(['organization_id', 'tenant_profile_id', 'as_of_date'], 'opening_balances_org_tenant_index');
            $table->index(['organization_id', 'import_batch_id'], 'opening_balances_org_batch_index');
            $table->index(['organization_id', 'unit_id'], 'opening_balances_org_unit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
