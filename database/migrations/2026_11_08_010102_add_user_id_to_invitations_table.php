<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One-time sign-in links (DATABASE_SCHEMA.md § 1 `invitations`, kind
 * `sign_in_link`; P03 decision 4) are invitations rows that open one
 * existing account: `user_id` is that account. Empty for every other kind.
 * Deleting the account kills the link (null). Built in P03-01b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('phone')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
