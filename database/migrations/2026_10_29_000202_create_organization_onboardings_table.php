<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The signup wizard's saved state, one row per organization
 * (ONBOARDING_COMPLIANCE.md § 2, DATABASE_SCHEMA.md § 1): the step the
 * owner is on, so leaving and coming back resumes there, and what the Tier
 * step recorded for the Transaction Tier application (P01-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step', 20);
            $table->unsignedBigInteger('declared_annual_volume_kobo')->nullable();
            $table->unsignedTinyInteger('requested_tier')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_onboardings');
    }
};
