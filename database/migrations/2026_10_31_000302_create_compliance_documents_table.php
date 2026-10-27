<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Compliance documents (ONBOARDING_COMPLIANCE.md § 5.3, DATABASE_SCHEMA.md
 * § 9). The file is on the private compliance disk (config/compliance.php),
 * encrypted by the app; `disk` and `path` point at it (no media library row:
 * compliance files stay apart from other media by design). Document numbers
 * and names are encrypted. `expiring_notified_at` / `expired_notified_at`
 * make the daily expiry check tell the owner once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tier_application_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->text('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('document_number')->nullable();
            $table->text('holder_name')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expiring_notified_at')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'tier_application_id', 'type'], 'compliance_documents_org_application_type_index');
            $table->index(['organization_id', 'status'], 'compliance_documents_org_status_index');
            $table->index(['status', 'expires_at'], 'compliance_documents_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_documents');
    }
};
