<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoProperty's own invoice numbers (DATABASE_SCHEMA.md § 3
 * `platform_invoices.number`, `PR-{YYYY}-{seq}`): one platform-wide
 * sequence per Lagos year, locked while a number is taken
 * (Modules\SaasBilling\Invoices\PlatformInvoiceNumbers), never max(id)+1.
 * First used by SMS credit bundles (P05-03); subscription invoices (P06-02)
 * share it. A platform table: no organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoice_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_counters');
    }
};
