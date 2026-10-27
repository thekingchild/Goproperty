<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Transaction Tier applications (ONBOARDING_COMPLIANCE.md § 5.3,
 * DATABASE_SCHEMA.md § 9): the org-owner asks for Tier 2 or 3, uploads the
 * documents, submits; compliance approves, rejects or asks for more
 * information. `open_organization_id` equals `organization_id` while the
 * application is open and is null once decided, so its unique index allows
 * one open application per organization (MySQL and MariaDB both allow
 * many NULLs in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('open_organization_id')->nullable()->unique();
            $table->unsignedTinyInteger('from_tier');
            $table->unsignedTinyInteger('requested_tier');
            $table->unsignedBigInteger('declared_annual_volume_kobo')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('compliance_contact')->nullable();
            $table->text('applicant_note')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'tier_applications_org_status_index');
            $table->index(['status', 'submitted_at'], 'tier_applications_status_submitted_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_applications');
    }
};
