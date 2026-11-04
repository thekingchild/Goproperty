<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operations dashboard's alerts (P08-03; PLATFORM_CONSOLE.md § 2
 * "Alerts", DATABASE_SCHEMA.md "operations_alerts"): one row per incident
 * of a rule (scheduler stopped, a backup failed, ...) for one subject,
 * from the check that raised it until the check that found it cleared.
 * Written only by App\Platform\Operations\Alerts\AlertMonitor. A platform
 * table: no organization, no personal data.
 *
 * `open_key` is "rule|subject" while the incident is open and NULL once it
 * is resolved, so the unique index allows one open incident per rule and
 * subject (MySQL and MariaDB allow repeated NULLs) and keeps the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('rule', 40);
            $table->string('subject', 120)->default('');
            $table->string('open_key', 170)->nullable()->unique();
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->string('raised_by', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedSmallInteger('notifications')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'id']);
            $table->index(['rule', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_alerts');
    }
};
