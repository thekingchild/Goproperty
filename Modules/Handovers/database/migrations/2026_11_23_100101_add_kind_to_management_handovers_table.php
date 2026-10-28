<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a management handover is (DATABASE_SCHEMA.md § 1,
 * BILLING_PAYMENTS.md § 7.1; P04-04): `management` (a landlord hands
 * buildings to an agency to manage; every row so far) or `return` (an
 * agency hands buildings it held without a handover to the landlord's own
 * organization: one-way, it ends as `returned`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('management_handovers', function (Blueprint $table) {
            $table->string('kind', 16)->default('management')->after('landlord_organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('management_handovers', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
