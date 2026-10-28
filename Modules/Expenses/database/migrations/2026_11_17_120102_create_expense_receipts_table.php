<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Expense receipts (P03-12; DATABASE_SCHEMA.md § 4 `expense_receipts`):
 * each file kept for an expense — a vendor's receipt, an invoice, a
 * photographed page — in the expense's `expense_receipts` media collection
 * (evidence, ARCHITECTURE.md § 9). DB § 4 had one `receipt_media_id` on the
 * expense; a row per file lets an expense keep several and lets "remove"
 * archive one file (who, when, why) while the file stays (AGENT.md § 3
 * rule 7). Written only by Modules\Expenses\Recording\ExpenseBook.
 *
 * `uploaded_at` uses CURRENT_TIMESTAMP as its default: a bare NOT NULL
 * TIMESTAMP would update itself on every change on MariaDB 10.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->unsignedBigInteger('media_id');
            $table->char('file_sha256', 64);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('media_id', 'expense_receipts_media_unique');
            $table->index(['organization_id', 'expense_id', 'archived_at'], 'expense_receipts_org_expense_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_receipts');
    }
};
