<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PL-02 (late fees on overdue rent, docs/plans/late-fees-2026-11-11.md;
 * DATABASE_SCHEMA.md § 4 `late_fees`): one row per rent bill that was
 * charged a late fee, written in the same transaction as the fee bill, so
 * a rent bill is charged once, ever. Written only by
 * Modules\LateFees\Charges\LateFeeCharger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('rent_bill_id')->constrained('bills')->restrictOnDelete();
            $table->foreignId('fee_bill_id')->constrained('bills')->restrictOnDelete();
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('base_kobo');
            $table->unsignedBigInteger('amount_kobo');
            $table->date('charged_on');
            $table->timestamps();

            $table->unique('rent_bill_id', 'late_fees_rent_bill_unique');
            $table->index(['organization_id', 'charged_on'], 'late_fees_org_charged_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_fees');
    }
};
