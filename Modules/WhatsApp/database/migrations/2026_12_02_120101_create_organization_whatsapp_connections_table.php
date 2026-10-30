<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organization's own WhatsApp Business Platform account
 * (DATABASE_SCHEMA.md § 7 `organization_whatsapp_connections`,
 * ARCHITECTURE.md § 6.7): one row per organization. Meta bills the agency
 * for its messages; GoProperty never resells them. Written only by
 * Modules\WhatsApp\Connections\WhatsAppConnections.
 *
 * - `access_token`, `app_secret`: encrypted with the app key (text);
 *   `access_token_last4` for the masked display. Emptied on disconnect.
 * - `webhook_ulid`: the organization's part of /webhooks/whatsapp/{ulid};
 *   `webhook_verify_token` answers Meta's GET handshake; the app secret's
 *   `X-Hub-Signature-256` proves each status call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('waba_id', 32)->nullable();
            $table->string('phone_number_id', 32)->nullable();
            $table->string('display_phone_number', 32)->nullable();
            $table->string('verified_name', 120)->nullable();
            $table->text('access_token')->nullable();
            $table->string('access_token_last4', 4)->nullable();
            $table->text('app_secret')->nullable();
            $table->string('webhook_ulid', 26)->unique();
            $table->string('webhook_verify_token', 64);
            $table->string('status', 20)->default('pending_test');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_error', 300)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('templates_synced_at')->nullable();
            $table->string('last_sync_error', 300)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_whatsapp_connections');
    }
};
