<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Uploaded bank statements (DATABASE_SCHEMA.md § 4 `bank_statement_imports`,
 * ONBOARDING_COMPLIANCE.md § 4.1): one row per uploaded file of one of the
 * organization's bank accounts. Written only by
 * Modules\Collections\Statements\StatementImports and its processing job.
 *
 * - `media_id`: the uploaded file, kept as evidence (media collection
 *   `statements`); no foreign key, like the other media pointers, so the
 *   NDPA erasure tooling can purge files.
 * - `column_mapping`: the header row and the file column of each field, as
 *   used for this file (the account's saved mapping is in
 *   `bank_statement_column_mappings`).
 * - The counts are the import report: transaction rows read, lines added
 *   (credits and debits), duplicates skipped (lines an earlier statement
 *   already brought in), unreadable rows (listed in `report` with the
 *   reason), and lines matched (P03-07b).
 *
 * History: statements stay with the organization that uploaded them, also
 * when a building is handed over (P03-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_bank_account_id')->constrained('organization_bank_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->string('file_name', 150);
            $table->string('format', 10);
            $table->json('column_mapping')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('lines_total')->default(0);
            $table->unsignedInteger('lines_added')->default(0);
            $table->unsignedInteger('lines_credit')->default(0);
            $table->unsignedInteger('lines_debit')->default(0);
            $table->unsignedInteger('lines_duplicate')->default(0);
            $table->unsignedInteger('lines_unreadable')->default(0);
            $table->unsignedInteger('lines_matched')->default(0);
            $table->json('report')->nullable();
            $table->string('status', 20)->default('processing');
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'organization_bank_account_id'], 'bank_statement_imports_org_account_index');
            $table->index(['organization_id', 'status'], 'bank_statement_imports_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
