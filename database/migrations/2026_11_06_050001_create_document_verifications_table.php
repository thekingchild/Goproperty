<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Verification codes of issued documents (DATABASE_SCHEMA.md § 4
 * "document_verifications", BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7):
 * one row per code, so a code is unique across every kind of document and
 * the public /verify/{code} page (P03-05b) can find the organization and
 * the module that issued it before anything else is read. The module keeps
 * the document itself (e.g. `bills.verification_code`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40);
            $table->char('code', 10)->unique();
            $table->timestamps();

            $table->index(['organization_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
