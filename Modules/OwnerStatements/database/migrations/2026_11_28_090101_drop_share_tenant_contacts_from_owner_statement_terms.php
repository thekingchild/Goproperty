<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P03-16 (product owner 2026-10-29, docs/plans/owner-visibility-2026-10-29.md):
 * owners always see their tenants' details, so the agency's per-owner
 * "share tenant contact details" switch (P08-05) is gone. down() puts the
 * column back as it was made (off for every owner); what agencies had
 * chosen is not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_statement_terms', function (Blueprint $table) {
            $table->dropColumn('share_tenant_contacts');
        });
    }

    public function down(): void
    {
        Schema::table('owner_statement_terms', function (Blueprint $table) {
            $table->boolean('share_tenant_contacts')->default(false)->after('utilities_to_owner');
        });
    }
};
