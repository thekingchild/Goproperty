<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent records (P09-02; NDPA 2023 s. 26, DATABASE_SCHEMA.md
 * "consent_records"): what each person agreed to and when — the terms and
 * privacy policy at signup and when accepting an invitation, and product
 * email opted in or out — with the version of the text, the IP address
 * and the browser. Append-only, written only by App\Privacy\Consents.
 * A platform table: `organization_id` is the organization whose
 * invitation was accepted, null for signup and the person's own settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('document', 20);
            $table->boolean('granted');
            $table->string('version', 40)->nullable();
            $table->string('source', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'document', 'id']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
