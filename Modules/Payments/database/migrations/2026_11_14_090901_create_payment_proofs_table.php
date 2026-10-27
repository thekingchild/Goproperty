<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Proofs of payment (P03-09; DATABASE_SCHEMA.md § 4 `payment_proofs`,
 * ONBOARDING_COMPLIANCE.md § 4.1): what a tenant (portal) or a member on
 * the tenant's behalf says was paid — amount, date, sender, bank, the
 * narration — with the file (a screenshot, a photo of a teller slip, a
 * PDF) in the proof's `payment_proofs` media collection (evidence). Each
 * proof records one payment waiting for confirmation (source
 * `proof_upload`, `payment_id` unique); the payment's status is the
 * proof's review (confirmed or rejected through PaymentService).
 *
 * - `file_sha256`: the file's hash, to flag the same file uploaded again in
 *   the organization. `first_upload_sha256` is set only on the first proof
 *   with that hash (unique per organization; NULLs repeat), so two uploads
 *   of the same file racing each other cannot both count as the first: the
 *   later one waits on the unique key, then is flagged with
 *   `duplicate_of_proof_id`. Flagged, never refused automatically.
 * - Evidence (AGENT.md § 3 rule 7): never deleted; "remove" archives
 *   (`archived_at`, by, reason) and keeps the file.
 * - `submitted_at` uses CURRENT_TIMESTAMP as its default: a bare NOT NULL
 *   TIMESTAMP would update itself on every change on MariaDB 10.6.
 *
 * History of the organization that received the proof (P03-14 moves
 * nothing here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('bills')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->date('paid_on');
            $table->string('sender_name', 150);
            $table->string('bank_name', 100);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->char('file_sha256', 64);
            $table->char('first_upload_sha256', 64)->nullable();
            $table->foreignId('duplicate_of_proof_id')->nullable()->constrained('payment_proofs')->restrictOnDelete();
            $table->string('submitted_by', 20);
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('payment_id', 'payment_proofs_payment_unique');
            $table->unique(['organization_id', 'first_upload_sha256'], 'payment_proofs_org_first_upload_unique');
            $table->index(['organization_id', 'file_sha256'], 'payment_proofs_org_sha256_index');
            $table->index(['organization_id', 'tenant_profile_id', 'submitted_at'], 'payment_proofs_org_tenant_index');
            $table->index(['organization_id', 'bill_id'], 'payment_proofs_org_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
