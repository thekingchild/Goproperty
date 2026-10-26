<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The setup checklist on the workspace dashboard (ONBOARDING_COMPLIANCE.md
 * § 2 step 7): one row per organization and item (tier, plan, collections,
 * data, branding). Later features mark their item done through
 * App\Onboarding\OnboardingChecklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('item', 32);
            $table->string('status', 20)->default('pending');
            $table->json('details')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'item']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_checklist_items');
    }
};
