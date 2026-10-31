<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Refunds of payments into GoProperty's own account (P06-07b;
 * BILLING_PAYMENTS.md § 8; docs/plans/P06-subtasks.md defaults 57–59).
 * Written only by Modules\SaasBilling\Refunds\PlatformRefunds; never
 * deleted, and what was asked (payment, amount, method, reason, requester)
 * never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_payment_id')->constrained('platform_payments')->restrictOnDelete();
            $table->foreignId('platform_invoice_id')->constrained('platform_invoices')->restrictOnDelete();
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 20);
            $table->string('reason', 500);
            $table->foreignId('requested_by_staff_id')->constrained('platform_staff')->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->unique()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('executed_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->string('paystack_refund_id', 40)->nullable();
            $table->string('bank_reference', 100)->nullable();
            $table->boolean('invoice_reopened')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason', 300)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['platform_payment_id', 'status']);
            $table->index(['status', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_refunds');
    }
};
