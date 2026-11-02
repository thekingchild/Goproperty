<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The members of a Building Group (DATABASE_SCHEMA.md § 6 `group_members`;
 * P07-01). A tenant is a member while they hold an active lease in the
 * building, kept in step by Modules\BuildingGroup\Groups\BuildingGroups:
 * one row per group and tenant record (`tenant_profile_id`), `left_at` set
 * when they leave and cleared when they come back, so a member's history
 * and mute stay with the row. `user_id` is for members who are users
 * rather than tenant records (staff, owners: later tasks); a tenant's
 * account is found through their tenant record. Never deleted. Timestamps
 * nullable (MariaDB 10.6, see tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_group_id')->constrained('building_groups')->restrictOnDelete();
            $table->string('member_type', 20);
            $table->foreignId('tenant_profile_id')->nullable()->constrained('tenant_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20)->default('member');
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['building_group_id', 'tenant_profile_id'], 'group_members_group_tenant_unique');
            $table->unique(['building_group_id', 'user_id'], 'group_members_group_user_unique');
            $table->index(['organization_id', 'building_group_id', 'left_at'], 'group_members_org_group_left_index');
            $table->index(['organization_id', 'tenant_profile_id'], 'group_members_org_tenant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
