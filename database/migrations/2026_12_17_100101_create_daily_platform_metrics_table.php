<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly platform analytics summary (P08-02; DATABASE_SCHEMA.md
 * "daily_platform_metrics", PLATFORM_CONSOLE.md § 3): one figure per Lagos
 * day, metric and dimension, written only by App\Platform\Analytics\
 * MetricsSnapshot and read only by the console's System analytics page.
 * Not organization-scoped: it holds platform totals (and per-organization
 * counts keyed by id for the leaderboard), no personal data.
 *
 * `dimension` is never NULL ('' = no dimension): MySQL and MariaDB treat
 * NULLs as distinct in a unique index, which would let a re-run add a
 * second row instead of replacing the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_platform_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('metric', 48);
            $table->string('dimension', 64)->default('');
            $table->bigInteger('value');
            $table->timestamps();

            $table->unique(['date', 'metric', 'dimension']);
            $table->index(['metric', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_platform_metrics');
    }
};
