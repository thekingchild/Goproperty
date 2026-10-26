<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Drafts and published versions of the theme, website and SEO settings
 * (DATABASE_SCHEMA.md § 8, PLATFORM_CONSOLE.md § 9.8). At most one draft;
 * every publish keeps a numbered snapshot that can be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->nullable()->unique();
            $table->string('status', 20);
            $table->json('keys');
            $table->unsignedBigInteger('created_by_staff_id')->nullable()->index();
            $table->unsignedBigInteger('published_by_staff_id')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_versions');
    }
};
