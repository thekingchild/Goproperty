<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `payments.bank_statement_line_id` (P03-10a, no foreign key until the
 * statement lines existed) now points at `bank_statement_lines`
 * (P03-07b): the line a statement-match payment was recorded from cannot
 * be dropped from under it. Not unique: a line whose suggested payment was
 * rejected may be matched again, and both payments name it (the rejected
 * one as history). In the Collections module, which owns the lines; the
 * Payments module's migrations stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('bank_statement_line_id', 'payments_bank_statement_line_id_foreign')->references('id')->on('bank_statement_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_bank_statement_line_id_foreign');
        });
    }
};
