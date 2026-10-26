<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Suspension by platform staff (P01-06, PLATFORM_CONSOLE.md § 1A): when it
 * began, and the status to return to on reactivation (a restricted
 * organization stays restricted). Reason and staff member are in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('trial_ends_at');
            $table->string('status_before_suspension', 20)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'status_before_suspension']);
        });
    }
};
