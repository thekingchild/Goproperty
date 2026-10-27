<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The online payments GoProperty started in the organization's own
 * Paystack account (ONBOARDING_COMPLIANCE.md § 4.2, P03-08): one row per
 * transaction initialized from a bill's pay page, with the amount asked
 * for. A webhook or the return from Paystack settles it once: verified
 * with Paystack (GET /transaction/verify), then confirmed through the
 * Payments module's PaymentRecorder, or held for review (amount or
 * currency not what was asked, bill no longer owing it). Written only by
 * Modules\OnlinePayments\Transactions\PaystackTransactions.
 *
 * - `reference`: GoProperty's transaction reference sent to Paystack,
 *   unique everywhere (it also finds the organization on the return).
 * - `payment_id`: the payment recorded for it (confirmed, or pending when
 *   held). The payment itself is unique per `(organization_id,
 *   paystack_reference)` in `payments`.
 *
 * History: stays with the organization that started it (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('currency', 3)->default('NGN');
            $table->string('mode', 10);
            $table->string('status', 20)->default('initialized');
            $table->string('hold_reason', 300)->nullable();
            $table->unsignedBigInteger('paid_amount_kobo')->nullable();
            $table->string('paid_currency', 3)->nullable();
            $table->string('channel', 30)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'paystack_transactions_org_status_index');
            $table->index(['organization_id', 'bill_id'], 'paystack_transactions_org_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_transactions');
    }
};
