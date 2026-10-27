<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Buildings (DATABASE_SCHEMA.md § 3 `properties`). The slug is unique per
 * organization (archived and soft-deleted rows included: a new building
 * gets the next free slug). `owner_profile_id` is optional: an owner who
 * runs their own organization owns its buildings without an owner profile.
 * `photo_media_id` is the cover photo, one of the building's `photos`
 * media; like organizations.logo_media_id it has no foreign key (the media
 * library deletes a removed photo's row itself, and the writer clears the
 * cover first). Archiving sets `status`; soft deletes are left to import
 * rollback (DATA_IMPORT.md § 5). Import provenance (§ 8A): `import_batch_id`
 * has no foreign key (import_batches belongs to the Import module) and
 * `external_ref` is unique per organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_profile_id')->nullable()->constrained('owner_profiles')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 100);
            $table->string('property_type', 24);
            $table->string('address', 300);
            $table->string('state', 40);
            $table->string('lga', 64)->nullable();
            $table->string('landmark', 160)->nullable();
            $table->text('description')->nullable();
            $table->json('amenities')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->unsignedBigInteger('photo_media_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug'], 'properties_org_slug_unique');
            $table->unique(['organization_id', 'external_ref'], 'properties_org_external_ref_unique');
            $table->index(['organization_id', 'import_batch_id'], 'properties_org_import_batch_index');
            $table->index(['organization_id', 'status', 'name'], 'properties_org_status_name_index');
            $table->index(['organization_id', 'owner_profile_id'], 'properties_org_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
