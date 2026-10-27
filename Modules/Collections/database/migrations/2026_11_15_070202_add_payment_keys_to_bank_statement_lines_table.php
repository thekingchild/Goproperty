<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `bank_statement_lines.payment_id` (P03-07a, no key until matching
 * existed) is now unique and points at `payments` (P03-07b): a payment is
 * the match of one line at most, and cannot be dropped from under it.
 * Several NULLs (lines not matched) are allowed by a unique index on
 * MySQL and MariaDB alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->unique('payment_id', 'bank_statement_lines_payment_unique');
            $table->foreign('payment_id', 'bank_statement_lines_payment_id_foreign')->references('id')->on('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropForeign('bank_statement_lines_payment_id_foreign');
            $table->dropUnique('bank_statement_lines_payment_unique');
        });
    }
};
