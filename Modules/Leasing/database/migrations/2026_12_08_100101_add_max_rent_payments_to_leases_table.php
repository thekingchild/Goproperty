<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How many payments a tenant may make on one rent bill of this lease
 * (P03-17; docs/plans/rent-part-payments-2026-10-31.md): null = the
 * organization's default (setting `leasing.max_rent_payments`, itself
 * empty = no limit), otherwise 1 to 12. Set by owners and administrators,
 * audited; read when a payment arrives, so it applies to open bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_rent_payments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('max_rent_payments');
        });
    }
};
