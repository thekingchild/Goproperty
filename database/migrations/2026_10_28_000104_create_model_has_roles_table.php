<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Role assignments (spatie, with teams; DATABASE_SCHEMA.md § 1). A
 * customer's role is assigned per organization: `organization_id` is the
 * organization it applies in. Staff roles are global, so their rows have no
 * organization. Spatie's stub makes the team column part of the primary key,
 * which cannot be null; a surrogate key and a unique index allow both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();

            $table->unique(['organization_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_unique');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};
