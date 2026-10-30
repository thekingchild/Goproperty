<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payments of GoProperty's invoices (DATABASE_SCHEMA.md § 3
 * `platform_payments`; BILLING_PAYMENTS.md § 4): built in P05-03 for
 * Paystack checkouts of SMS credit bundles, into GoProperty's OWN Paystack
 * account (its income, never tenant money: AGENT.md § 3 rule 8). Bank
 * transfers (P06-04) and stored-card charges (P06-03) join later.
 * Organization-scoped.
 *
 * - `paystack_reference`: unique, `PINV-{invoice number}-a{n}` (§ 4.1, § 4.3
 *   rule 1): a replayed webhook finds the same row and does nothing.
 * - `status`: pending → confirmed (verified by fetch: the amount, currency
 *   and mode matched) | failed (Paystack says failed or abandoned) | held
 *   (paid, but not as asked: for platform staff to review, never applied).
 * - `hold_reason`: why it was held or failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_invoice_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3);
            $table->string('paystack_reference', 100)->nullable()->unique();
            $table->string('paystack_channel', 30)->nullable();
            $table->string('status', 15);
            $table->string('hold_reason', 300)->nullable();
            $table->unsignedBigInteger('paid_amount_kobo')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'platform_payments_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payments');
    }
};
