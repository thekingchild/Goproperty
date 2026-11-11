<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Paystack's fee when the organization's Paystack account passes its fees
 * to the payer (product owner, 2026-11-11): the payer is charged the
 * amount asked plus the fee (`paid_amount_kobo`), Paystack keeps the fee
 * and settles the amount asked, and only the amount asked is credited to
 * the bill. Null when the payer paid no fee on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payer_fee_kobo')->nullable()->after('paid_amount_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropColumn('payer_fee_kobo');
        });
    }
};
