<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Transaction Tiers' terms, versioned (P06-08; ONBOARDING_COMPLIANCE.md
 * § 5.2 "Editing the limits", DATABASE_SCHEMA.md § 9 `transaction_tiers`):
 * one row per tier and version with the tier's name, limits in kobo (null =
 * no cap), the single-payment amount above which a payment is reviewed, the
 * required and optional documents (App\Enums\ComplianceDocumentType values)
 * and the review target in business days. A version waits for a second staff
 * member (`status` pending, `approval_request_id`) and applies, once
 * approved, from `effective_from` (a Lagos date). The version in force on a
 * day is the latest approved one whose date is on or before it. Never
 * edited once proposed, never deleted; a scheduled one may be cancelled
 * before its date. Written only by App\Platform\Compliance\TierSettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('tier');
            $table->unsignedInteger('version');
            $table->string('name', 60);
            $table->unsignedBigInteger('annual_volume_limit_kobo')->nullable();
            $table->unsignedBigInteger('single_payment_limit_kobo')->nullable();
            $table->unsignedBigInteger('review_threshold_kobo')->nullable();
            $table->json('required_documents');
            $table->json('optional_documents');
            $table->unsignedTinyInteger('review_sla_days')->nullable();
            $table->date('effective_from');
            $table->string('status', 20);
            $table->string('reason', 500)->nullable();
            $table->foreignId('proposed_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('approved_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tier', 'version']);
            $table->index(['tier', 'status', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_tiers');
    }
};
