<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The account's notification choices (P05-02; DATABASE_SCHEMA.md § 1
 * `users.preferences`, "channel toggles"): e.g. {"reminders": {"email":
 * true, "sms": false}}. Null means every channel on. Read and written only
 * through App\Notifications\Preferences\NotificationPreferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('phone_confirmed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
