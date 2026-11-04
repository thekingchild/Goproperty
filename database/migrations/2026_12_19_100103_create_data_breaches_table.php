<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal data breach register (P09-02; NDPA 2023 s. 40,
 * docs/runbooks/breach-response.md, DATABASE_SCHEMA.md "data_breaches"):
 * one row per incident, from when GoProperty became aware of it (the
 * 72-hour clock for the Nigeria Data Protection Commission) to its close.
 * Written only by App\Privacy\Breaches\BreachRegister; never deleted. A
 * platform table: organizations are named by number, people by count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_breaches', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 20)->nullable()->unique();
            $table->string('title', 160);
            $table->text('description');
            $table->timestamp('aware_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->text('data_involved')->nullable();
            $table->unsignedInteger('people_affected')->nullable();
            $table->string('organizations_affected', 1000)->nullable();
            $table->text('likely_consequences')->nullable();
            $table->text('measures_taken')->nullable();
            $table->string('risk', 10);
            $table->string('status', 16);
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('commission_notified_at')->nullable();
            $table->string('commission_reference', 100)->nullable();
            $table->timestamp('customers_notified_at')->nullable();
            $table->timestamp('people_notified_at')->nullable();
            $table->string('not_notified_reason', 1000)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_note', 1000)->nullable();
            $table->foreignId('recorded_by_staff_id')->constrained('platform_staff')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'aware_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_breaches');
    }
};
