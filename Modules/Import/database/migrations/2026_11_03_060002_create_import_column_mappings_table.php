<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Column mappings an organization saved for reuse (DATABASE_SCHEMA.md § 8A,
 * DATA_IMPORT.md § 2.2): per target (`kind`, e.g. `tenants`), a name the
 * agency chose and the mapping (json: the file's header, normalised, =>
 * the target's column key). Saving under an existing name replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('name', 80);
            $table->json('mapping');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'name'], 'import_column_mappings_org_kind_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_column_mappings');
    }
};
