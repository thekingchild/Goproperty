<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Property owners' person or company details (DATABASE_SCHEMA.md § 1): an
 * owner may never sign in, so this is not a user. `display_name` is the
 * company name or "First Last", kept for lists, search and import matching.
 * The ID number is encrypted (a text column: the ciphertext is long and
 * differs on each write). Import provenance (§ 8A): `import_batch_id` has no
 * foreign key (import_batches arrives with the Import module, P02-06a) and
 * `external_ref` is unique per organization. Archiving is a soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->string('display_name', 200);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('state', 40)->nullable();
            $table->string('lga', 64)->nullable();
            $table->string('id_type', 8)->default('none');
            $table->text('id_value')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'external_ref'], 'owner_profiles_org_external_ref_unique');
            $table->index(['organization_id', 'import_batch_id'], 'owner_profiles_org_import_batch_index');
            $table->index(['organization_id', 'deleted_at', 'display_name'], 'owner_profiles_org_deleted_name_index');
            $table->index(['organization_id', 'phone'], 'owner_profiles_org_phone_index');
            $table->index(['organization_id', 'email'], 'owner_profiles_org_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_profiles');
    }
};
