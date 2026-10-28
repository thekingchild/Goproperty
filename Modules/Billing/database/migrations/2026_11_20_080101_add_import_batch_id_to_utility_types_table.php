<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Import provenance for utility types (P03-13b; DATABASE_SCHEMA.md § 8A):
 * the Utilities import sheet adds a utility type the organization does not
 * have yet (DATA_IMPORT.md § 1 row 7), and the import's rollback archives
 * the types it added. `import_batch_id` without a foreign key, like every
 * provenance column; types made on the Utilities page keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_types', function (Blueprint $table) {
            $table->unsignedBigInteger('import_batch_id')->nullable()->after('active');
            $table->index(['organization_id', 'import_batch_id'], 'utility_types_org_batch_index');
        });
    }

    public function down(): void
    {
        Schema::table('utility_types', function (Blueprint $table) {
            $table->dropIndex('utility_types_org_batch_index');
            $table->dropColumn('import_batch_id');
        });
    }
};
