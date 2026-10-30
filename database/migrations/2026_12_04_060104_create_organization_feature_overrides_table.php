<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A feature granted or withheld for one organization outside its plan
 * (P06-01a; DATABASE_SCHEMA.md § 2 `organization_feature_overrides`,
 * ARCHITECTURE.md § 6.10): it wins over the plan until `expires_at` (null =
 * no end). A switch uses `enabled`; a limit uses `limit_value` (null =
 * unlimited). Read by App\Plans\Features; granted by platform staff (the
 * console's screen comes with P06-07), with the reason and the staff member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('feature_key', 40);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('limit_value')->nullable();
            $table->string('reason', 500);
            $table->foreignId('granted_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'feature_key'], 'org_feature_overrides_org_feature_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_feature_overrides');
    }
};
