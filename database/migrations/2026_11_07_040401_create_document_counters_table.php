<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Document number sequences (DATABASE_SCHEMA.md § 4 "document_counters",
 * ARCHITECTURE.md § 6.5): one row per organization, document type and
 * year, holding the last number given out. App\Documents\DocumentNumberer
 * locks the row inside the issuing transaction, so numbers are gap-free
 * and a rolled-back issue gives its number back. The unique key is what
 * makes creating a year's first row safe when two issues race for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('doc_type', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'doc_type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
