<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organization's own Paystack account (DATABASE_SCHEMA.md § 4
 * `organization_paystack_connections`, ONBOARDING_COMPLIANCE.md § 4.2):
 * one row per organization. Tenants' online payments settle into the
 * agency's account with the agency's keys; GoProperty never receives the
 * money (AGENT.md § 3 rule 8). Written only by
 * Modules\OnlinePayments\Connections\PaystackConnections.
 *
 * - `secret_key`: encrypted with the app key (text); `secret_key_last4`
 *   for the masked display. Both keys are emptied on disconnect.
 * - `webhook_token`: the random part of /webhooks/paystack/{webhook_token}
 *   (orchestrator decision 2), unique, rotatable; the URL finds the
 *   organization, the signature (HMAC-SHA512 with this secret) proves it.
 * - `mode`: test or live, from the key prefixes.
 *
 * Not history: the connection is settings of the organization; nothing
 * here follows a building when it is handed over (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_paystack_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('public_key', 150)->nullable();
            $table->text('secret_key')->nullable();
            $table->string('secret_key_last4', 4)->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->string('mode', 10)->nullable();
            $table->string('status', 20)->default('pending_test');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_error', 300)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_paystack_connections');
    }
};
