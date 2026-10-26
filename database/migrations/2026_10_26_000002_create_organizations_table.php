<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Customers (DATABASE_SCHEMA.md § 1): each organization is an isolated
 * tenant of the row-level tenancy in ARCHITECTURE.md § 4. Money in kobo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->json('previous_slugs')->nullable();
            $table->string('type', 20);
            $table->text('cac_number')->nullable();
            $table->string('address')->nullable();
            $table->string('state', 64)->nullable();
            $table->string('lga', 64)->nullable();
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->string('status', 20)->default('onboarding');
            $table->unsignedTinyInteger('transaction_tier')->default(1);
            $table->boolean('allow_support_access')->default(true);
            $table->boolean('platform_covered')->default(false);
            $table->json('branding')->nullable();
            $table->unsignedBigInteger('online_payment_cap_kobo')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
