<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How a confirmed payment was split across bills (DATABASE_SCHEMA.md § 4
 * `payment_allocations`): written once, when the payment is confirmed, in
 * the same transaction as the bills' paid amounts (Contracts\BillLedger
 * of the Billing module) and never changed. The allocations of a payment
 * add up to at most its amount; the rest went to tenant credit. One row
 * per bill and payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamps();

            $table->unique(['payment_id', 'bill_id'], 'payment_allocations_payment_bill_unique');
            $table->index(['organization_id', 'bill_id'], 'payment_allocations_org_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
