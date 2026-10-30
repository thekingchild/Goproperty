<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every SMS GoProperty sends, and what became of it (P05-02;
 * DATABASE_SCHEMA.md § 7 `notification_deliveries`; ARCHITECTURE.md § 6.7).
 * One row per message, written before it is sent, so a retry never sends
 * twice and a failure stays visible (console delivery log; P05-05's health
 * dashboard; P05-03 charges credits from these rows).
 *
 * - `recipient_contact`: the number as sent (E.164). `body`: the text as
 *   sent, kept so a retry sends the same words.
 * - `status`: queued → sending → sent → delivered | undelivered, or failed
 *   (gave up after its retries, or the outcome of an attempt is unknown:
 *   never sent twice).
 * - `provider_message_id`: GoText's `uid`; `provider_status` its own word.
 * - `pages`, `cost_kobo`: as GoText reports them, when it does.
 * - `source_type` / `source_id`: what asked for it (a reminder).
 * - `dedupe_key`: unique, so a replay queues nothing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('notification_type', 60);
            $table->string('channel', 10);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_contact', 32);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('status', 12);
            $table->string('provider', 20);
            $table->string('provider_message_id', 100)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('pages')->nullable();
            $table->unsignedInteger('cost_kobo')->nullable();
            $table->string('source_type', 20)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('dedupe_key', 191);
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();

            $table->unique('dedupe_key', 'notification_deliveries_dedupe_unique');
            $table->index(['organization_id', 'status', 'queued_at'], 'notification_deliveries_org_status_index');
            $table->index(['organization_id', 'source_type', 'source_id'], 'notification_deliveries_org_source_index');
            $table->index(['status', 'queued_at'], 'notification_deliveries_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
