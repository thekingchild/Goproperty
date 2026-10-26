<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Permissions given straight to a user or staff member (spatie, with
 * teams). Same shape as model_has_roles: `organization_id` is the
 * organization the grant applies in, null for staff (no organization).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();

            $table->unique(['organization_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_unique');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
    }
};
