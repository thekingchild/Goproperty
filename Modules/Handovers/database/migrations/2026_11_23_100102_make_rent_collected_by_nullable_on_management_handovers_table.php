<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Who collects rent (BILLING_PAYMENTS.md § 7.5) does not apply to a
 * one-way return (§ 7.1, P04-04): the buildings become the landlord's
 * own again. Null on a return; a management keeps `agency` or `owner`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('management_handovers', function (Blueprint $table) {
            $table->string('rent_collected_by', 16)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('management_handovers')->whereNull('rent_collected_by')->update(['rent_collected_by' => 'agency']);

        Schema::table('management_handovers', function (Blueprint $table) {
            $table->string('rent_collected_by', 16)->nullable(false)->default('agency')->change();
        });
    }
};
