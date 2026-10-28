<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The tenants a management handover moves, and how each one's credit is
 * settled (DATABASE_SCHEMA.md § 1; P03 decision 11). `tenant_profile_id`
 * is the tenant in the landlord's organization (their credit and unpaid
 * bills stay there, as history); `receiving_tenant_profile_id` the tenant
 * that stands for them where the buildings went (set on acceptance). The
 * credit and unpaid bills are as they stood when the handover was offered,
 * then accepted. The landlord chooses `pass_to_agency` (the default) or
 * `refund`; `settled_at` and `settled_kobo` record the settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_handover_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('management_handover_id')->constrained('management_handovers')->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->foreignId('receiving_tenant_profile_id')->nullable()->constrained('tenant_profiles')->restrictOnDelete();
            $table->string('tenant_name', 170);
            $table->unsignedBigInteger('credit_kobo')->default(0);
            $table->unsignedInteger('unpaid_bills')->default(0);
            $table->unsignedBigInteger('unpaid_kobo')->default(0);
            $table->string('settlement', 16)->default('pass_to_agency');
            $table->unsignedBigInteger('settled_kobo')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settled_by_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['management_handover_id', 'tenant_profile_id'], 'mht_handover_tenant_unique');
            $table->index(['organization_id', 'management_handover_id'], 'mht_organization_handover_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_handover_tenants');
    }
};
