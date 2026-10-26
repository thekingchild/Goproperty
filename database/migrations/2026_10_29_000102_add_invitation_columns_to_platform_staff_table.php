<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Staff lifecycle (PLATFORM_CONSOLE.md § 1): invite by email → set password
 * and 2FA on first sign-in → active; deactivated staff keep their row (no
 * hard deletes). The invitation token is stored only as a SHA-256 hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->foreignId('invited_by_staff_id')->nullable()->after('status')->constrained('platform_staff')->nullOnDelete();
            $table->string('invitation_token_hash', 64)->nullable()->unique()->after('invited_by_staff_id');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token_hash');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invitation_expires_at');
            $table->timestamp('deactivated_at')->nullable()->after('invitation_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->dropForeign(['invited_by_staff_id']);
            $table->dropUnique(['invitation_token_hash']);
            $table->dropColumn(['invited_by_staff_id', 'invitation_token_hash', 'invitation_expires_at', 'invitation_accepted_at', 'deactivated_at']);
        });
    }
};
