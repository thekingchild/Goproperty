<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The plan chosen in the signup wizard (ONBOARDING_COMPLIANCE.md § 2 step 4),
 * a key from the plan catalogue. Interim: P06-01 creates the subscription
 * from it and the subscription becomes the source of truth
 * (DATABASE_SCHEMA.md § 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('signup_plan_key', 32)->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('signup_plan_key');
        });
    }
};
