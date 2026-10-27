<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The invitation that carries a billing transfer's link (kind
 * `org_billing_transfer`, DATABASE_SCHEMA.md § 1) points at its transfer.
 * Added by the SaasBilling module with the table it refers to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('billing_transfer_id')->nullable()->after('target_organization_id')
                ->constrained('billing_transfer_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_transfer_id');
        });
    }
};
