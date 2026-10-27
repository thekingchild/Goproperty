<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `payments.receipt_id` (P03-10a, no foreign key until receipts existed)
 * now points at `receipts` (P03-10b): a payment's receipt cannot be dropped
 * from under it (DATABASE_SCHEMA.md § 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('receipt_id', 'payments_receipt_id_foreign')->references('id')->on('receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_receipt_id_foreign');
        });
    }
};
