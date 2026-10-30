<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An organization's SMS credit balance (P05-03; DATABASE_SCHEMA.md § 7
 * `sms_credit_balances`; BILLING_PAYMENTS.md § 1.2): one row per
 * organization, the row every credit change locks, so two SMS sent at the
 * same time can never take it below zero. It always equals the sum of the
 * organization's `sms_credit_entries`, kept in two pools:
 *
 * - `allowance_credits`: what is left of the plan's monthly allowance for
 *   `allowance_month` (Lagos, "2026-12"); it expires when the next month's
 *   is granted. `allowance_granted`: that month's allowance.
 * - `purchased_credits`: bought (or added by GoProperty staff); never expire.
 * - `low_notified_month` / `empty_notified_month`: the month owners and
 *   administrators were last told the balance fell to 20 % / zero.
 *
 * Written only by App\Sms\Credits\SmsCredits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_credit_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->integer('allowance_credits')->default(0);
            $table->integer('purchased_credits')->default(0);
            $table->char('allowance_month', 7)->nullable();
            $table->integer('allowance_granted')->default(0);
            $table->char('low_notified_month', 7)->nullable();
            $table->char('empty_notified_month', 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_credit_balances');
    }
};
