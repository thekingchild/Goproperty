<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Staff role columns (DATABASE_SCHEMA.md § 8, PLATFORM_CONSOLE.md § 1):
 * `is_default` marks a shipped role, `is_protected` the `super-admin` role
 * (never edited or deactivated), `active` lets a super-admin retire a role
 * once nobody holds it. Customer roles keep the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('guard_name');
            $table->boolean('is_protected')->default(false)->after('is_default');
            $table->string('description', 255)->nullable()->after('is_protected');
            $table->boolean('active')->default(true)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'is_protected', 'description', 'active']);
        });
    }
};
