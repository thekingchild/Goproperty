<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two-person rule (PLATFORM_CONSOLE.md § 1, DATABASE_SCHEMA.md § 8): an
 * action that needs a second staff member waits here until someone else
 * with the right permission approves it, rejects it, or it expires.
 * `payload` holds what the action needs to run on approval (e.g. the role
 * to grant). Platform-level: no organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->string('summary', 255);
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_staff_id')->constrained('platform_staff')->restrictOnDelete();
            $table->foreignId('approved_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->foreignId('rejected_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'action']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
