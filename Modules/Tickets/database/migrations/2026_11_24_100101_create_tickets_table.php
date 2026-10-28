<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Maintenance tickets (DATABASE_SCHEMA.md § 5 `tickets`, ARCHITECTURE.md
 * § 6.6; P04-01). Written only by Modules\Tickets\Tickets\TicketService
 * (AGENT.md § 3 rule 5); never deleted for good (soft deletes: only a
 * tenant's unsent draft is ever discarded).
 *
 * `number` is the organization's ticket number (`TKT-000001`, one sequence
 * per organization through App\Documents\DocumentNumberer, docs/plans/
 * P04-subtasks.md default 4), given when the ticket is sent, so a draft
 * has none and numbers have no gaps. `property_id` is the unit's
 * building, kept so agent narrowing (BuildingAccess) finds a building's
 * tickets without joining the Properties module's tables. `lease_id` and
 * `tenant_profile_id` are empty for a unit with no tenant (staff raise
 * those). Every TIMESTAMP is nullable (MariaDB 10.6, see
 * tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('number', 20)->nullable();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained('leases')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->nullable()->constrained('tenant_profiles')->restrictOnDelete();
            $table->string('source', 16);
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 16);
            $table->string('priority', 8)->default('medium');
            $table->string('status', 16)->default('draft');
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number'], 'tickets_org_number_unique');
            $table->index(['organization_id', 'status'], 'tickets_org_status_index');
            $table->index(['assigned_to_user_id', 'status'], 'tickets_assignee_status_index');
            $table->index(['organization_id', 'property_id', 'status'], 'tickets_org_property_status_index');
            $table->index(['organization_id', 'tenant_profile_id'], 'tickets_org_tenant_index');
            $table->index(['organization_id', 'lease_id'], 'tickets_org_lease_index');
            $table->index(['organization_id', 'status', 'resolved_at'], 'tickets_org_status_resolved_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
