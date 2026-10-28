<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P08-05 (owner statements, DATABASE_SCHEMA.md § 4
 * "owner_statement_settlements"): the agency's Settled / Unsettled mark on
 * one owner's month, natural key (organization, owner profile, month). A
 * settled month keeps the statement's figures as they were (`figures`, the
 * snapshot every later view and the PDF are built from) and the branding;
 * unsettling keeps the row with who, when and why. Never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_statement_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_profile_id')->constrained('owner_profiles')->restrictOnDelete();
            $table->date('month');
            $table->string('status', 20);
            $table->date('paid_on')->nullable();
            $table->string('reference', 100)->nullable();
            $table->bigInteger('net_due_kobo')->nullable();
            $table->json('figures')->nullable();
            $table->json('branding_snapshot')->nullable();
            $table->unsignedSmallInteger('version')->default(0);
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unsettled_at')->nullable();
            $table->foreignId('unsettled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unsettle_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'owner_profile_id', 'month'], 'owner_statement_settlements_natural_unique');
            $table->index(['organization_id', 'month'], 'owner_statement_settlements_org_month_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_statement_settlements');
    }
};
