<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Support impersonation (PLATFORM_CONSOLE.md § 6, DATABASE_SCHEMA.md § 8):
 * one row per session a staff member starts on a customer user, with the
 * reason, mode, approval (write mode), the one-time hand-off token (hash
 * only, 60 seconds), the 30-minute limit and how it ended. Pages viewed and
 * actions go to the audit log (log `impersonation`). A platform record, not
 * customer data: the customer app never reads it as a list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('platform_staff')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('mode', 16);
            $table->foreignId('approval_request_id')->nullable()->unique()->constrained('approval_requests')->restrictOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->char('handoff_token_hash', 64)->nullable();
            $table->timestamp('handoff_expires_at')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 20)->nullable();
            $table->foreignId('ended_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'started_at']);
            $table->index(['staff_id', 'started_at']);
            $table->index(['user_id', 'started_at']);
            $table->index(['ended_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
