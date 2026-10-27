<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The parties of a bill as at issue (P03-05c; DATABASE_SCHEMA.md § 4
 * `bills.details`, BRANDING_DOCUMENTS.md § 2 item 3): the tenant's name,
 * phone and email, the building's name and address, the unit and the
 * lease's number and cycle, as the invoice prints them. Written once by
 * Bills\BillIssuer, never changed, so a later rename, a moved building
 * (management handover, P03-14) or an archived tenant never changes an
 * issued invoice. Nullable: bills issued before this column print their
 * parties as they are now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->json('details')->nullable()->after('branding_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
