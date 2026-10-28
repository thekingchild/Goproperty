<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A ticket's status history (DATABASE_SCHEMA.md § 5 `ticket_status_history`;
 * P04-01): one row per move of the state machine, the ticket's creation
 * (from nothing) included, written by Modules\Tickets\Tickets\TicketService
 * in the same transaction as the move and mirrored to the activity log.
 * Rows are never changed or deleted. `changed_by_user_id` is empty for the
 * system (tickets-auto-close). `changed_at` uses CURRENT_TIMESTAMP and the
 * timestamps are nullable (MariaDB 10.6, see
 * tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index(['organization_id', 'ticket_id'], 'ticket_status_history_org_ticket_index');
            $table->index(['organization_id', 'to_status', 'changed_at'], 'ticket_status_history_org_to_changed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_status_history');
    }
};
