<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who started a payment through GoProperty's Paystack checkout (P06-03;
 * DATABASE_SCHEMA.md § 2 `platform_payments`): the owner who pressed Pay
 * on a subscription invoice (an SMS bundle's buyer is on its invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initiated_by_user_id');
        });
    }
};
