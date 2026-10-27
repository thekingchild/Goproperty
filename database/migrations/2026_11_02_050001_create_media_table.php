<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The media library's table (spatie/laravel-medialibrary 11, its
 * create_media_table stub) with `organization_id`, so every file belongs to
 * one organization and App\Media\Media is organization-scoped like any
 * other customer record (ARCHITECTURE.md § 4 and § 9, DATABASE_SCHEMA.md
 * "media"). The model morph index starts with the organization, which every
 * query filters on.
 *
 * Compliance documents are not here: they keep their own encrypted disk
 * (P01-03, `compliance_documents`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();

            $table->index(['organization_id', 'model_type', 'model_id', 'collection_name'], 'media_organization_model_index');
            $table->index(['organization_id', 'collection_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
