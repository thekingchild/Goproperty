<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoProperty's own demo organization (P09-05; BILLING_PAYMENTS.md § 1.4):
 * never given a subscription, so never invoiced, metered, dunned or
 * suspended; sends no SMS; left out of the console's analytics. Set only
 * by `goproperty:demo-organization` (Database\Seeders\DemoOrganizationSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('platform_covered');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
