<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per tenant with credit history: the tenant's credit now (the sum
 * of their tenant_credit_entries, never below zero). Added beside
 * DATABASE_SCHEMA.md § 4's ledger because taking credit must be serialised:
 * a payment adding credit and a new bill using it lock this row
 * (SELECT … FOR UPDATE) inside their transactions, so credit is never used
 * twice. Written only by Modules\Payments\Credit\TenantCredit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_credit_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_profile_id')->constrained('tenant_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('balance_kobo')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'tenant_profile_id'], 'tenant_credit_balances_org_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_credit_balances');
    }
};
