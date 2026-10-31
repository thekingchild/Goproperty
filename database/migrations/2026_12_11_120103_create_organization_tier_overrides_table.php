<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Custom Transaction Tier limits for one organization (P06-08;
 * ONBOARDING_COMPLIANCE.md § 5.2 "Per-organization override",
 * DATABASE_SCHEMA.md § 9 `organization_tier_overrides`): a yearly and/or
 * single-payment limit in kobo that applies instead of the tier's (null =
 * the tier's own), with the reason, the staff member and an end
 * (`expires_at`, required by the console; nullable only for MariaDB's
 * TIMESTAMP rule). One row per organization, replaced by a new override and
 * ended by setting `expires_at`; never deleted. Written only by
 * App\Platform\Compliance\TierOverrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_tier_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('annual_volume_limit_kobo')->nullable();
            $table->unsignedBigInteger('single_payment_limit_kobo')->nullable();
            $table->string('reason', 500);
            $table->foreignId('set_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_tier_overrides');
    }
};
