<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who was told what about a ticket (DATABASE_SCHEMA.md § 5
 * `ticket_notices`; P04-03, docs/plans/P04-subtasks.md default 9): one row
 * per announcement (`key`: a status move or an assignment, later a
 * message) and recipient (`user:{id}`, or `tenant:{id}` for a tenant with
 * an email address and no portal account), written by
 * Modules\Tickets\Notices\TicketNotifier before the notification is
 * queued, so a retried job or a replayed event tells each party once
 * (AGENT.md § 3 rule 6). `notification_id` is the in-app notification's
 * id (`notifications.id`); `mailed_at` is set once the email went, so a
 * retried mail job does not send it again. History of the organization;
 * never deleted. Timestamps nullable (MariaDB 10.6, see
 * tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->string('key', 80);
            $table->string('recipient', 40);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('side', 10);
            $table->string('event', 40);
            $table->json('channels');
            $table->uuid('notification_id')->unique();
            $table->timestamp('mailed_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'key', 'recipient'], 'ticket_notices_ticket_key_recipient_unique');
            $table->index(['organization_id', 'ticket_id'], 'ticket_notices_org_ticket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notices');
    }
};
