<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The buildings of a management handover (DATABASE_SCHEMA.md § 1): the
 * building keeps its id when it moves, so `property_id` names it in either
 * organization. Its name and address as offered are kept here, because
 * each side can read the building itself only while it is theirs. The
 * owner profile it had in the landlord's organization is kept for the
 * return. A building is in at most one open handover (checked by the
 * service under a lock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_handover_buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('management_handover_id')->constrained('management_handovers')->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('property_name', 160);
            $table->string('property_address', 300)->nullable();
            $table->unsignedInteger('unit_count')->default(0);
            $table->foreignId('previous_owner_profile_id')->nullable()->constrained('owner_profiles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['management_handover_id', 'property_id'], 'mhb_handover_property_unique');
            $table->index(['organization_id', 'management_handover_id'], 'mhb_organization_handover_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_handover_buildings');
    }
};
