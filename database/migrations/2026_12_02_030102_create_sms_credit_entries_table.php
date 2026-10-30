<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The SMS credit ledger (P05-03; DATABASE_SCHEMA.md § 7 `sms_credit_entries`):
 * append-only, never changed or deleted. Balance = sum of `credits`
 * (sms_credit_balances holds the running total).
 *
 * - `reason`: monthly_allowance, purchase, usage, adjustment, expiry.
 * - `pool`: allowance or purchased, the pool the credits went into or came from.
 * - `credits`: + allowance/purchase, − usage/expiry; an adjustment either way.
 * - `balance_after`: the whole balance once this entry was written.
 * - `month`: the Lagos month the entry belongs to ("2026-12"; for an expiry,
 *   the month whose allowance expired).
 * - `notification_delivery_id`: the SMS a usage (or its give-back) is for.
 * - `platform_invoice_id`: the SMS credit invoice a purchase was paid by
 *   (SaasBilling's `platform_invoices`; no foreign key across the module).
 * - `platform_staff_id` and `note`: who made an adjustment and why.
 * - `idempotency_key`: unique per organization, so a replayed grant, purchase,
 *   charge or give-back writes nothing twice (AGENT.md § 3 rule 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('reason', 20);
            $table->string('pool', 10);
            $table->integer('credits');
            $table->integer('balance_after');
            $table->char('month', 7)->nullable();
            $table->foreignId('notification_delivery_id')->nullable()->constrained('notification_deliveries')->restrictOnDelete();
            $table->unsignedBigInteger('platform_invoice_id')->nullable();
            $table->foreignId('platform_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->string('idempotency_key', 80);
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key'], 'sms_credit_entries_org_key_unique');
            $table->index(['organization_id', 'id'], 'sms_credit_entries_org_id_index');
            $table->index(['organization_id', 'reason', 'created_at'], 'sms_credit_entries_org_reason_index');
            $table->index(['organization_id', 'platform_invoice_id'], 'sms_credit_entries_org_invoice_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_credit_entries');
    }
};
