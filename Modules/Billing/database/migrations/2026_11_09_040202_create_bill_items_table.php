<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The lines of a bill (DATABASE_SCHEMA.md § 4 `bill_items`): what the bill
 * charges, as printed on the invoice (BRANDING_DOCUMENTS.md § 2 item 4).
 * `amount_kobo` is the line's total (a bill's amount is the sum of its
 * lines); `quantity` is shown, not multiplied; a line may carry its own
 * period. Organization-scoped like its bill; written with the bill and
 * never changed or deleted afterwards (history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->restrictOnDelete();
            $table->string('label', 120);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedInteger('quantity')->default(1);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'bill_id'], 'bill_items_org_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_items');
    }
};
