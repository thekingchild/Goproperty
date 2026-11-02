<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Posts of a Building Group (DATABASE_SCHEMA.md § 6 `group_posts`; P07-02).
 * Written only by Modules\BuildingGroup\Posts\GroupPosts: `body` is the
 * sanitized HTML subset (bold, italic, lists, links; Posts\RichText), at
 * most 5,000 characters of text, empty for a post of images only; the
 * images are media rows of the post (`group_images`). The author is a user
 * writing as a tenant of the building (`author_tenant_profile_id`, the
 * tenant record they are a member through) or as staff. `last_activity_at`
 * is bumped by every comment, so the feed shows active posts first.
 * Removed by its author with a soft delete. Timestamps nullable (MariaDB
 * 10.6, see tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_group_id')->constrained('building_groups')->restrictOnDelete();
            $table->string('author_kind', 20);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_tenant_profile_id')->nullable()->constrained('tenant_profiles')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->unsignedTinyInteger('image_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->string('status', 20)->default('published');
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'building_group_id', 'status', 'last_activity_at'], 'group_posts_org_group_status_activity_index');
            $table->index(['organization_id', 'author_user_id'], 'group_posts_org_author_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_posts');
    }
};
