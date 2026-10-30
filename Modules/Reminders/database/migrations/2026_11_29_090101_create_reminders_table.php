<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The reminder engine's plan and record (DATABASE_SCHEMA.md § 7
 * `reminders`; P05-01, ARCHITECTURE.md § 6.7): one row per reminder rung,
 * channel and recipient, written by Modules\Reminders\Reminders\ReminderPlanner
 * on the rung's day and sent from 08:00 Lagos by ReminderSender.
 *
 * - `remindable_type` / `remindable_id`: `bill` or `lease` (short keys, the
 *   records belong to the Billing and Leasing modules: no foreign keys);
 *   `lease_id` and `period_start` say which lease period a rent reminder
 *   is about, also before its bill exists.
 * - `due_date`: the bill's due date (the lease's last day for a renewal
 *   alert); `offset_days`: the rung, signed (T−7 is −7, overdue +1 is 1);
 *   `send_on`: the Lagos day it belongs to (due date + offset).
 * - `dedupe_key`, unique per organization, is the idempotency guarantee
 *   (DATABASE_SCHEMA.md integrity rule 5, AGENT.md § 3 rule 6):
 *   `type|subject|channel|offset|due_date|recipient`, the subject being
 *   `lease:{id}@{period start}` for rent, `bill:{id}` for utilities and
 *   overdue bills, `lease:{id}` for renewals. A rerun, or two runs at
 *   once, plan and send nothing twice.
 * - `recipient`: `user:{id}` or `tenant:{id}`; `recipient_email` the address
 *   an email reminder goes to.
 * - `notification_id`: the notification's id (the in-app row's id), so a
 *   retried job writes nothing twice; `delivered_at` is set once the
 *   email or in-app row went.
 *
 * History of the organization; never deleted. Timestamps nullable
 * (MariaDB 10.6, tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('remindable_type', 10);
            $table->unsignedBigInteger('remindable_id');
            $table->unsignedBigInteger('lease_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('due_date');
            $table->smallInteger('offset_days');
            $table->date('send_on');
            $table->string('channel', 10);
            $table->string('recipient', 40);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('recipient_tenant_profile_id')->nullable();
            $table->string('recipient_email', 191)->nullable();
            $table->timestamp('send_at')->nullable();
            $table->string('status', 10);
            $table->string('skip_reason', 30)->nullable();
            $table->string('dedupe_key', 191);
            $table->uuid('notification_id')->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'dedupe_key'], 'reminders_org_dedupe_unique');
            $table->index(['organization_id', 'status', 'send_at'], 'reminders_org_status_send_index');
            $table->index(['organization_id', 'remindable_type', 'remindable_id'], 'reminders_org_remindable_index');
            $table->index(['organization_id', 'recipient_tenant_profile_id'], 'reminders_org_tenant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
