<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The console's impersonation log reads a session's timeline by staff
 * member (PLATFORM_CONSOLE.md § 6): every entry written during a support
 * session carries `impersonator_staff_id`. An index only; the hash chain
 * is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['impersonator_staff_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['impersonator_staff_id', 'id']);
        });
    }
};
