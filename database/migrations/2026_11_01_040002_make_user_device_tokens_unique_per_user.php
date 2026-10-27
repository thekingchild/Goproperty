<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One browser, several accounts (DATABASE_SCHEMA.md § 1 `user_devices`):
 * App\Auth\Devices\KnownDevices reuses the browser's `gp_device` token when
 * a second account signs in from it, which the table-wide unique index
 * refused (a 500 on the second account's sign-in, found when a new member
 * accepted an invitation in a browser another account had used). The
 * token is unique per account instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->unique(['token_hash', 'user_id']);
            $table->dropUnique(['token_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->unique(['token_hash']);
            $table->dropUnique(['token_hash', 'user_id']);
        });
    }
};
