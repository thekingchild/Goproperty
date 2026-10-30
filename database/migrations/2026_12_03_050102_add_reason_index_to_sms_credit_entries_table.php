<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The console's SMS spend (P05-05; PLATFORM_CONSOLE.md § 2 "Messaging")
 * adds up the credit ledger of every organization by reason for the last
 * 24 hours and 7 days; the existing indexes start with the organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_credit_entries', function (Blueprint $table) {
            $table->index(['reason', 'created_at'], 'sms_credit_entries_reason_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('sms_credit_entries', function (Blueprint $table) {
            $table->dropIndex('sms_credit_entries_reason_created_index');
        });
    }
};
