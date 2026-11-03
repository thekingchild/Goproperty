<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Members' reports of a Building Group post or comment (DATABASE_SCHEMA.md
 * § 6 `group_reports`; P07-04). One report per person and item, ever
 * (unique key); open until a moderator hides or removes the item
 * (`actioned`) or dismisses its reports (`dismissed`). The reporter side
 * and tenant record mirror the posts' author columns. Written only by
 * Modules\BuildingGroup\Moderation\AbuseReports; never deleted. Timestamps
 * nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_group_id')->constrained('building_groups')->restrictOnDelete();
            $table->string('reportable_type', 20);
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_kind', 20);
            $table->foreignId('reporter_tenant_profile_id')->nullable()->constrained('tenant_profiles')->restrictOnDelete();
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_user_id', 'reportable_type', 'reportable_id'], 'group_reports_reporter_item_unique');
            $table->index(['organization_id', 'building_group_id', 'status'], 'group_reports_org_group_status_index');
            $table->index(['organization_id', 'reportable_type', 'reportable_id'], 'group_reports_org_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_reports');
    }
};
