<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Settings store (DATABASE_SCHEMA.md § 8): one row per key and scope.
 * Global rows use scope_type "global" and scope_id 0, not NULL, because
 * MySQL and MariaDB let NULLs repeat in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 64)->default('global');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
