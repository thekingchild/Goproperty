<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The tenant credit ledger (DATABASE_SCHEMA.md § 4 `tenant_credit_entries`):
 * + what a confirmed payment paid beyond the tenant's bills (overpayment),
 * − what was taken from it for a newly issued bill (applied_to_bill), later
 * refunds and adjustments. Rows are never changed or deleted; the tenant's
 * credit is their sum (kept, under a row lock, in tenant_credit_balances).
 *
 * `applied_bill_id` equals `bill_id` on an applied_to_bill entry and is
 * null otherwise: unique, so credit is applied to a bill at most once even
 * if the BillIssued event is handled twice (AGENT.md § 3 rule 6).
 * Import provenance (§ 8A) for opening credits (P03-13b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->bigInteger('amount_kobo');
            $table->string('reason', 30);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->restrictOnDelete();
            $table->unsignedBigInteger('applied_bill_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();

            $table->unique('applied_bill_id', 'tenant_credit_entries_applied_bill_unique');
            $table->unique(['organization_id', 'external_ref'], 'tenant_credit_entries_org_external_ref_unique');
            $table->index(['organization_id', 'tenant_profile_id'], 'tenant_credit_entries_org_tenant_index');
            $table->index(['organization_id', 'payment_id'], 'tenant_credit_entries_org_payment_index');
            $table->index(['organization_id', 'import_batch_id'], 'tenant_credit_entries_org_import_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_credit_entries');
    }
};
