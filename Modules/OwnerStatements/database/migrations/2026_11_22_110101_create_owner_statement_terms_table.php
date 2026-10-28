<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P08-05 (owner statements, DATABASE_SCHEMA.md § 4 "owner_statement_terms"):
 * what the agency agreed with one owner: its commission on rent collected
 * (basis points; null = not set yet), whether utilities collected pass to
 * the owner, and whether the owner sees tenants' phone and email. One row
 * per owner profile, made on the first change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_statement_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_profile_id')->constrained('owner_profiles')->restrictOnDelete();
            $table->unsignedInteger('commission_bps')->nullable();
            $table->boolean('utilities_to_owner')->default(false);
            $table->boolean('share_tenant_contacts')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'owner_profile_id'], 'owner_statement_terms_org_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_statement_terms');
    }
};
