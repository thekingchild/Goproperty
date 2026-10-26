<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * spatie/laravel-permission roles with teams (team = organization,
 * DATABASE_SCHEMA.md § 1). `organization_id` is null for roles every
 * organization shares (the customer roles in ARCHITECTURE.md § 5) and for
 * staff roles; it is set only for a role one organization defines for
 * itself. MySQL allows repeated NULLs in the unique index, so the shared
 * roles are kept unique by the seeder and by spatie's create().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 125);
            $table->string('guard_name', 25);
            $table->timestamps();

            $table->unique(['organization_id', 'name', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
