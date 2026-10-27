<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Agent ↔ building assignments (DATABASE_SCHEMA.md § 3 `agent_building`,
 * ARCHITECTURE.md § 5): an agent with at least one row here sees and works
 * on those buildings only. One row per agent and building; the unique index
 * leads with `organization_id` (ARCHITECTURE.md § 4: every unique index
 * includes it) and also answers "this agent's buildings here", the lookup
 * every narrowed list and policy makes. The second index lists a building's
 * agents. Rows are plain links: unassigning, or removing the member,
 * deletes the row (audited by the writer); a building is never hard
 * deleted, and a user only by the erasure tooling, so both cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_building', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'property_id'], 'agent_building_org_user_property_unique');
            $table->index(['organization_id', 'property_id'], 'agent_building_org_property_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_building');
    }
};
