<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bank transfers to GoProperty's own account (P06-04; BILLING_PAYMENTS.md
 * § 5; DATABASE_SCHEMA.md § 2 `platform_payments`): an owner sends a proof
 * against an open subscription invoice, which is a `platform_payments` row
 * with method `bank_transfer`, status `pending`, waiting for platform staff.
 * The proof alone never marks the invoice paid; staff confirm it (status
 * `confirmed`) or reject it (`rejected`, with the reason).
 *
 * - `bank_transfer_proof_media_id`: the proof file (media collection
 *   `platform_payment_proofs`, evidence: never deleted).
 * - `proof_sha256`: the file's SHA-256, to flag the same file sent again.
 * - `stated_amount_kobo`, `transferred_on`, `sender_name`, `sender_bank`,
 *   `transfer_narration`: what the owner says about the transfer.
 * - `reviewed_by_staff_id`, `reviewed_at`: who confirmed or rejected it.
 * - `rejection_reason`: why it was rejected, shown to the owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->foreignId('bank_transfer_proof_media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->char('proof_sha256', 64)->nullable();
            $table->unsignedBigInteger('stated_amount_kobo')->nullable();
            $table->date('transferred_on')->nullable();
            $table->string('sender_name', 120)->nullable();
            $table->string('sender_bank', 80)->nullable();
            $table->string('transfer_narration', 100)->nullable();
            $table->foreignId('reviewed_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->index(['method', 'status'], 'platform_payments_method_status_index');
            $table->index(['organization_id', 'proof_sha256'], 'platform_payments_org_proof_sha_index');
        });
    }

    public function down(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->dropIndex('platform_payments_org_proof_sha_index');
            $table->dropIndex('platform_payments_method_status_index');
            $table->dropConstrainedForeignId('reviewed_by_staff_id');
            $table->dropConstrainedForeignId('bank_transfer_proof_media_id');
            $table->dropColumn(['proof_sha256', 'stated_amount_kobo', 'transferred_on', 'sender_name', 'sender_bank', 'transfer_narration', 'reviewed_at', 'rejection_reason']);
        });
    }
};
