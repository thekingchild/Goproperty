<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emoji reactions on Building Group posts and comments (DATABASE_SCHEMA.md
 * § 6 `group_reactions`; P07-02). `reactable_type` is `group_post` or
 * `group_comment`, `emoji` a key of the curated set
 * (Modules\BuildingGroup\Enums\Reaction). One reaction per person and
 * item (ARCHITECTURE.md § 6.8): the unique key leaves the emoji out, so
 * choosing another replaces it and the same one again removes it. Written
 * only by Modules\BuildingGroup\Posts\GroupPosts. A reaction is a toggle,
 * not a record: removing one deletes its row. Timestamps nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reactable_type', 20);
            $table->unsignedBigInteger('reactable_id');
            $table->string('emoji', 20);
            $table->timestamps();

            $table->unique(['user_id', 'reactable_type', 'reactable_id'], 'group_reactions_user_item_unique');
            $table->index(['organization_id', 'reactable_type', 'reactable_id'], 'group_reactions_org_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_reactions');
    }
};
