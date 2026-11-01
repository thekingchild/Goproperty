<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who restricted an organization and when (P06-09; ONBOARDING_COMPLIANCE.md
 * § 5.5): `restriction_source` is `tier_monitoring` (the grace after a
 * `volume_100` flag ended; the daily run lifts it again) or `staff`
 * (Console → Customers; only staff lift it). Both are null while the
 * organization is not restricted. Written only by
 * App\Compliance\Monitoring\TierRestrictions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('restriction_source', 20)->nullable()->after('status_before_suspension');
            $table->timestamp('restricted_at')->nullable()->after('restriction_source');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['restriction_source', 'restricted_at']);
        });
    }
};
