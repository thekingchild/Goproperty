<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoProperty's subscription plans (P06-01a; BILLING_PAYMENTS.md § 1,
 * DATABASE_SCHEMA.md § 2 `plans`): one row per plan with what never changes
 * a bill (name, the landing page's lines, trial days, order). The priced
 * terms and features are versions (`plan_versions`), so a price change can
 * be given 30 days' notice. `key` is stable (signup, `organizations.
 * signup_plan_key`); `is_active` = offered to new signups; `custom` = priced
 * "from" (Enterprise). Written only by App\Platform\Plans\PlanEditor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 60);
            $table->string('audience', 160)->default('');
            $table->json('highlights')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('custom')->default(false);
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
