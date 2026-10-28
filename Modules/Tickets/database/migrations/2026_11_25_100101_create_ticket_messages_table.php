<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A ticket's thread (DATABASE_SCHEMA.md § 5 `ticket_messages`; P04-02):
 * replies between the tenant, the agency's staff and the building's owner,
 * and staff-only internal notes (`is_internal`). Written only by
 * Modules\Tickets\Messages\TicketMessages; rows are evidence, never changed
 * or deleted (no soft deletes). Attachments are media rows of the message
 * (collection `ticket_attachments`). Timestamps are nullable (MariaDB
 * 10.6, see tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->string('author_kind', 10);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->unsignedTinyInteger('attachment_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'ticket_id', 'is_internal'], 'ticket_messages_org_ticket_internal_index');
            $table->index(['organization_id', 'created_at'], 'ticket_messages_org_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
