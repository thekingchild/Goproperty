<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payments from tenants (DATABASE_SCHEMA.md § 4 `payments`,
 * ARCHITECTURE.md § 6.4): one incoming payment, which may settle several
 * bills. GoProperty only records money paid into the organization's own
 * account (AGENT.md § 3 rule 8). Written only by
 * Modules\Payments\Payments\PaymentService (rule 5); money is integer kobo
 * (rule 2).
 *
 * - `status`: pending_confirmation → confirmed or rejected (refunded is
 *   reserved for reversals, not built yet). A confirmed payment is never
 *   deleted or changed (DATABASE_SCHEMA.md integrity rule 3).
 * - `bill_id`: the bill the payment was made for, when known (the agent
 *   chose it, the reference in a narration); confirming settles it first.
 * - `idempotency_key`: unique per organization, so a webhook or a statement
 *   line recorded twice (Contracts\PaymentRecorder) makes one payment
 *   (rule 6); `paystack_reference` is unique per organization too.
 * - `paid_at`: the day the money arrived (a calendar date, Lagos).
 * - `bank_statement_line_id` / `receipt_id`: the statement line (P03-07)
 *   and the receipt (P03-10b); no foreign keys until those tables exist.
 * - Import provenance (§ 8A): `import_batch_id` without a foreign key,
 *   `external_ref` unique per organization.
 *
 * History: payments stay with the organization that recorded them, also
 * when the building is handed over (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('method', 20);
            $table->string('source', 30);
            $table->string('status', 30)->default('pending_confirmation');
            $table->date('paid_at');
            $table->string('payer_name', 150)->nullable();
            $table->string('payer_type', 20)->default('individual');
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('paystack_reference', 100)->nullable();
            $table->unsignedBigInteger('bank_statement_line_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmation_note', 500)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'idempotency_key'], 'payments_org_idempotency_unique');
            $table->unique(['organization_id', 'paystack_reference'], 'payments_org_paystack_reference_unique');
            $table->unique(['organization_id', 'external_ref'], 'payments_org_external_ref_unique');
            $table->index(['organization_id', 'status', 'paid_at'], 'payments_org_status_paid_index');
            $table->index(['organization_id', 'paid_at'], 'payments_org_paid_index');
            $table->index(['organization_id', 'tenant_profile_id', 'status'], 'payments_org_tenant_status_index');
            $table->index(['organization_id', 'bank_statement_line_id'], 'payments_org_statement_line_index');
            $table->index(['organization_id', 'import_batch_id'], 'payments_org_import_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
