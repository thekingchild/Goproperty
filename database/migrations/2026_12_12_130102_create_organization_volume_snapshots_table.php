<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per organization per Lagos day (P06-09; DATABASE_SCHEMA.md § 9
 * `organization_volume_snapshots`): what the daily Transaction Tier run
 * measured and the limits it measured against (null = no cap). A second run
 * on the same day replaces the day's row. Written only by
 * App\Compliance\Monitoring\TierMonitor; never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_volume_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('tier');
            $table->unsignedBigInteger('rolling_12m_volume_kobo');
            $table->unsignedBigInteger('forecast_12m_kobo');
            $table->unsignedBigInteger('largest_payment_kobo');
            $table->unsignedBigInteger('month_volume_kobo');
            $table->unsignedBigInteger('annual_limit_kobo')->nullable();
            $table->unsignedBigInteger('single_payment_limit_kobo')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'date'], 'organization_volume_snapshots_org_date_unique');
            $table->index(['date', 'tier'], 'organization_volume_snapshots_date_tier_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_volume_snapshots');
    }
};
