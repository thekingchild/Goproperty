<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Comments on Building Group posts (DATABASE_SCHEMA.md § 6
 * `group_comments`; P07-02), with one level of replies: `parent_id` is a
 * top-level comment of the same post (a reply to a reply is filed under
 * its parent). Written only by Modules\BuildingGroup\Posts\GroupPosts;
 * `body` is the same sanitized HTML subset as posts, at most 2,000
 * characters of text. Removed by its author with a soft delete. Timestamps
 * nullable (MariaDB 10.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('group_post_id')->constrained('group_posts')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('group_comments')->restrictOnDelete();
            $table->string('author_kind', 20);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_tenant_profile_id')->nullable()->constrained('tenant_profiles')->restrictOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('published');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'group_post_id', 'status'], 'group_comments_org_post_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_comments');
    }
};
