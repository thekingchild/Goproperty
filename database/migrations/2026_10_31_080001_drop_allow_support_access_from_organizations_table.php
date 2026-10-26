<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * No organization consent for support access (product owner, 2026-10-26;
 * PLATFORM_CONSOLE.md § 6): organizations have no setting to allow or
 * refuse impersonation. Control sits on GoProperty's side (staff
 * permissions, reason, 30-minute limit, blocked actions, audit trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('allow_support_access');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('allow_support_access')->default(true)->after('transaction_tier');
        });
    }
};
