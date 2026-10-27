<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Import batches (DATABASE_SCHEMA.md § 8A, DATA_IMPORT.md § 5): who ran
 * which file, the mapping, the dry-run report, commit progress and counts,
 * and the rollback. The uploaded file is a media row owned by the batch
 * (collection `imports`); the daily purge deletes it after 30 days, which
 * empties `media_id` (set null on delete) and sets `file_purged_at`.
 * `kind` is `workbook` or a registered target's key (owners, tenants, …).
 * `commit_cursor` is where the queued commit resumes ({target, row}),
 * saved in the same transaction as each chunk of rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('file_type', 8);
            $table->string('file_name', 160);
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('column_mapping')->nullable();
            $table->string('status', 24)->default('uploaded');
            $table->json('dry_run_report')->nullable();
            $table->timestamp('dry_run_at')->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_done')->default(0);
            $table->json('commit_cursor')->nullable();
            $table->json('result_counts')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('rolled_back_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('file_purged_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'import_batches_org_created_index');
            $table->index(['organization_id', 'status'], 'import_batches_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
