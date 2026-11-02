<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One Building Group per building and organization (DATABASE_SCHEMA.md § 6
 * `building_groups`; P07-01): opened by Modules\BuildingGroup\Groups\BuildingGroups
 * when the building is added, a lease of it changes, or the nightly sync
 * finds it missing. Unique per (organization, building): when a management
 * handover moves the building, the organization it leaves keeps its group,
 * closed, and the one it goes to opens its own (a return opens the old one
 * again). `name` empty = the building's name. Never deleted. Timestamps
 * nullable (MariaDB 10.6, see tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('description', 1000)->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'property_id'], 'building_groups_org_property_unique');
            $table->index(['organization_id', 'is_active'], 'building_groups_org_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_groups');
    }
};
