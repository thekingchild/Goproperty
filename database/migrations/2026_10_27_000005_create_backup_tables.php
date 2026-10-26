<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Backup destinations and runs (PLATFORM_CONSOLE.md § 4, DATABASE_SCHEMA.md
 * § 8). Destination credentials are an encrypted JSON blob and are never
 * shown after saving. One run row per job and destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 20);
            $table->text('config');
            $table->string('applies_to', 10)->default('both');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('triggered_by', 10);
            $table->unsignedBigInteger('triggered_by_staff_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_destinations');
    }
};
