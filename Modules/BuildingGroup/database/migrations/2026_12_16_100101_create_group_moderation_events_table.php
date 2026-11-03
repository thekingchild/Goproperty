<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What moderators did in a Building Group (DATABASE_SCHEMA.md § 6
 * `group_moderation_events`; P07-04): hiding, showing again and removing a
 * post or comment, pinning and unpinning, muting and unmuting a tenant,
 * dismissing reports and changing the group's settings, with who, when and
 * why. The trail moderators see on a hidden or removed item; the audit log
 * has an entry for each row too. Written only by
 * Modules\BuildingGroup\Moderation\GroupModeration; append-only (the model
 * refuses changes and deletes). Timestamps nullable, as every
 * organization-scoped table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_group_id')->constrained('building_groups')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'building_group_id', 'id'], 'group_moderation_events_org_group_index');
            $table->index(['organization_id', 'target_type', 'target_id'], 'group_moderation_events_org_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_moderation_events');
    }
};
