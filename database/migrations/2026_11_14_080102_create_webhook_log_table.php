<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every webhook delivery, kept before it is processed (DATABASE_SCHEMA.md
 * § 2 `webhook_log`, BILLING_PAYMENTS.md § 4.2, ARCHITECTURE.md § 10):
 * replay-safe, and the evidence of what a provider sent.
 *
 * - `organization_id` (nullable, orchestrator decision 2): the organization
 *   whose own Paystack account sent it (/webhooks/paystack/{webhook_token},
 *   P03-08); null for GoProperty's own Paystack (platform billing, P06-03).
 * - `payload`: the raw body exactly as received (text, not json: a json
 *   column may reorder keys, and the signature is over the raw bytes).
 * - `signature_valid`: whether the HMAC-SHA512 matched; forged or wrongly
 *   signed deliveries are kept too, marked false, and never processed.
 * - `outcome`: what processing did (confirmed, held, duplicate, ignored,
 *   not_paid, rejected); `processing_error` the last failure.
 *
 * Core table: written by OnlinePayments for organizations and later by the
 * platform for GoProperty's own account. Never changed by customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('event', 60)->nullable();
            $table->string('reference', 100)->nullable();
            $table->longText('payload');
            $table->boolean('signature_valid')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->string('processing_error', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'reference'], 'webhook_log_org_reference_index');
            $table->index(['organization_id', 'received_at'], 'webhook_log_org_received_index');
            $table->index(['provider', 'processed_at'], 'webhook_log_provider_processed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_log');
    }
};
