<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The invitation that carries a management handover's link (kind
 * `org_billing_transfer`, kept from P02-04; DATABASE_SCHEMA.md § 1) points
 * at its handover. Added by the Handovers module with the table it refers
 * to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('management_handover_id')->nullable()->after('target_organization_id')
                ->constrained('management_handovers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('management_handover_id');
        });
    }
};
