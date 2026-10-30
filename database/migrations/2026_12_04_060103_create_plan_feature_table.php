<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A plan version's features and limits (P06-01a; BILLING_PAYMENTS.md § 1.2,
 * ARCHITECTURE.md § 6.10; DATABASE_SCHEMA.md § 2 `plan_feature`): one row
 * per feature of the registry (App\Plans\Feature) per version. A switch
 * uses `enabled`; a limit uses `limit_value` (null = unlimited). Versioned
 * with the prices, so taking a feature away gets the same 30 days' notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->string('feature_key', 40);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['plan_version_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature');
    }
};
