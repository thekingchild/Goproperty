<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Compliance flags (P06-09; ONBOARDING_COMPLIANCE.md § 5.4,
 * DATABASE_SCHEMA.md § 9 `compliance_flags`): what the Transaction Tier
 * monitoring noticed about one organization. The subject is a plain type
 * and id (`payment`, `compliance_document`), never a foreign key into a
 * module's table; `tenant_profile_id` lets the structuring check find a
 * tenant's earlier flag. `dedupe_key` (unique) makes every check safe to
 * re-run. `grace_ends_at` is set on `volume_100` flags only. Written only
 * by App\Compliance\Monitoring\ComplianceFlags and the console's
 * App\Platform\Compliance\FlagQueue; never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->unsignedTinyInteger('tier')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('tenant_profile_id')->nullable();
            $table->unsignedBigInteger('metric_value_kobo')->nullable();
            $table->unsignedBigInteger('limit_kobo')->nullable();
            $table->json('details')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->string('resolution_note', 1000)->nullable();
            $table->foreignId('resolved_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('owners_notified_at')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->timestamps();

            $table->index(['organization_id', 'type', 'status'], 'compliance_flags_org_type_status_index');
            $table->index(['status', 'type', 'created_at'], 'compliance_flags_status_type_created_index');
            $table->index(['type', 'status', 'grace_ends_at'], 'compliance_flags_grace_index');
            $table->index(['organization_id', 'tenant_profile_id', 'type'], 'compliance_flags_org_tenant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_flags');
    }
};
