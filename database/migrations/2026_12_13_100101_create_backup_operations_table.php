<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What staff asked the backups console to do (P06-10; PLATFORM_CONSOLE.md
 * § 4, DATABASE_SCHEMA.md § 8): run a backup now, verify one, restore to
 * staging or production, or record a restore drill. Each row waits until
 * the scheduler's minute tick runs it in-process. No foreign keys: a
 * production restore replaces the whole database and carries these rows
 * over by id (App\Backup\Operations\ProductionRestore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_operations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('backup_type', 10)->nullable();
            $table->unsignedBigInteger('backup_run_id')->nullable();
            $table->string('target_database', 64)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('requested_by_staff_id')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->unsignedBigInteger('approved_by_staff_id')->nullable();
            $table->string('safety_backup_filename')->nullable();
            $table->string('reason', 500)->nullable();
            $table->text('result')->nullable();
            $table->date('drill_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['kind', 'status']);
            $table->index('backup_run_id');
        });

        Schema::table('backup_destinations', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('active');
            $table->string('check_problem', 500)->nullable()->after('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('backup_destinations', function (Blueprint $table) {
            $table->dropColumn(['checked_at', 'check_problem']);
        });

        Schema::dropIfExists('backup_operations');
    }
};
