<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoProperty staff (DATABASE_SCHEMA.md § 8), separate from customer users.
 * The installer creates the first account (INSTALLER.md § 5, step 6); the
 * `staff` guard and console come in P00-08. 2FA is mandatory but enrolled
 * at first console sign-in for command-line installs, so the secret starts
 * empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->json('ip_allowlist')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff');
    }
};
