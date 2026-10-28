<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organization's utilities (DATABASE_SCHEMA.md § 4 `utility_types`,
 * ARCHITECTURE.md § 6.4; P03-06a): security levy, water, electricity,
 * waste… Each has a charging mode (`flat`: a fixed amount each cycle;
 * `metered`: a rate per unit of measure times the consumption read from
 * the meter), a default amount in kobo (per cycle, or per unit of measure
 * when metered) and a default billing cycle (the lease enum,
 * Modules\Leasing\Contracts\BillingCycle). Units use a type through
 * `unit_utility_settings`, which may override the amount and cycle.
 *
 * Organization-wide configuration, written only by
 * Modules\Billing\Utilities\UtilityTypes; archived (`active` false), never
 * deleted: units' settings and readings keep pointing at it. Name and code
 * are unique per organization, archived types included (restore instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('code', 20);
            $table->string('charging_mode', 16);
            $table->string('unit_of_measure', 16)->nullable();
            $table->unsignedBigInteger('default_amount_kobo');
            $table->string('default_cycle', 16);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'utility_types_org_code_unique');
            $table->unique(['organization_id', 'name'], 'utility_types_org_name_unique');
            $table->index(['organization_id', 'active'], 'utility_types_org_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_types');
    }
};
