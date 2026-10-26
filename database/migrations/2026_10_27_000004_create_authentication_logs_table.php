<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Authentication log for customers and staff (PLATFORM_CONSOLE.md § 8,
 * DATABASE_SCHEMA.md § 8). Failed attempts against unknown accounts keep
 * only an HMAC of the typed email or phone. Kept one year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_logs', function (Blueprint $table) {
            $table->id();
            $table->string('authenticatable_type', 20);
            $table->unsignedBigInteger('authenticatable_id')->nullable();
            $table->char('attempted_identifier', 64)->nullable();
            $table->string('event', 40);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('location', 64)->nullable();
            $table->boolean('is_new_device')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['authenticatable_type', 'authenticatable_id', 'created_at'], 'authentication_logs_account_index');
            $table->index(['event', 'created_at']);
            $table->index('attempted_identifier');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_logs');
    }
};
