<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Receipts (P03-10b; DATABASE_SCHEMA.md § 4 `receipts`, BRANDING_DOCUMENTS.md
 * § 3, ARCHITECTURE.md § 6.4–6.5): one numbered receipt per confirmed
 * payment, written in the confirming transaction by
 * Modules\Payments\Receipts\Receipts. It keeps what the printed receipt
 * shows as it was at issue — the agency's branding (App\Documents\BrandingSnapshot,
 * images embedded) and the parties and payment (`details`: tenant, property,
 * payment, bills applied to, balances, who confirmed it, where a copy went)
 * — with its number (per organization and year, `document_counters`) and
 * verification code (kind `receipt`). The PDF is rendered once from this row
 * and kept as evidence in the row's `issued_documents` media
 * (`document_media_id`); a voided receipt's VOID copy is a second file
 * (`void_document_media_id`), the original is kept.
 *
 * Voids need two people (P03 decision 4): a request with a reason
 * (`void_requested_*`), then the approval of another owner or
 * administrator (`voided_at`, `voided_by_user_id` = who asked,
 * `void_approved_by_user_id` = who approved); an organization with a single
 * owner/administrator voids alone (`void_single_approver`).
 *
 * The number is unique per organization (decision 7; DB § 4 said globally).
 * History: it stays with the organization that issued it (P03-14); never
 * deleted (AGENT.md § 3 rule 7). Timestamps have explicit defaults or are
 * nullable (MariaDB 10.6, TimestampColumnsTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->string('number', 40);
            $table->char('verification_code', 10)->unique();
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamp('issued_at')->useCurrent();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('branding_snapshot');
            $table->json('details');
            $table->unsignedBigInteger('document_media_id')->nullable();
            $table->timestamp('void_requested_at')->nullable();
            $table->foreignId('void_requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_request_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('void_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->boolean('void_single_approver')->default(false);
            $table->unsignedBigInteger('void_document_media_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'receipts_org_number_unique');
            $table->index(['organization_id', 'issued_at'], 'receipts_org_issued_index');
            $table->index(['organization_id', 'tenant_profile_id'], 'receipts_org_tenant_index');
            $table->index(['organization_id', 'void_requested_at'], 'receipts_org_void_requested_index');
            $table->index(['organization_id', 'voided_at'], 'receipts_org_voided_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
