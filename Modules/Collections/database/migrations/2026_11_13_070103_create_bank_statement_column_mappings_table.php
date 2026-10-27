<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The column mapping saved per bank account (ONBOARDING_COMPLIANCE.md
 * § 4.1: "Column mapping is saved per bank"): which header of the bank's
 * export holds each field, and how its dates are written, so the next
 * statement of the same account maps itself. One row per account, replaced
 * by each upload that maps it. Written only by
 * Modules\Collections\Statements\StatementImports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_bank_account_id')->constrained('organization_bank_accounts', indexName: 'bank_statement_mappings_account_foreign')->restrictOnDelete();
            $table->json('columns');
            $table->string('date_order', 3)->default('dmy');
            $table->foreignId('saved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'organization_bank_account_id'], 'bank_statement_mappings_org_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_column_mappings');
    }
};
