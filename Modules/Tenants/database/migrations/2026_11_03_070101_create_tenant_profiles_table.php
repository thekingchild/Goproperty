<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tenants (renters) of an organization (DATABASE_SCHEMA.md § 1): the
 * person, distinct from a user (a tenant may not have portal access yet,
 * so `user_id` is nullable). The phone number (E.164) is how a tenant is
 * recognised, unique per organization, archived tenants included (an
 * import restores an archived match instead of colliding, P02 decision 3).
 * The ID number is encrypted (a text column: the ciphertext is long and
 * differs on each write). Import provenance (§ 8A): `import_batch_id` has
 * no foreign key (import_batches belongs to the Import module, which may be
 * switched off) and `external_ref` is unique per organization. Archiving
 * is a soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email')->nullable();
            $table->string('phone', 20);
            $table->string('address', 300)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->json('emergency_contact')->nullable();
            $table->string('kyc_status', 16)->default('unverified');
            $table->string('id_type', 16)->default('none');
            $table->text('id_value')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'phone'], 'tenant_profiles_org_phone_unique');
            $table->unique(['organization_id', 'external_ref'], 'tenant_profiles_org_external_ref_unique');
            $table->index(['organization_id', 'import_batch_id'], 'tenant_profiles_org_import_batch_index');
            $table->index(['organization_id', 'deleted_at', 'last_name', 'first_name'], 'tenant_profiles_org_deleted_name_index');
            $table->index(['organization_id', 'email'], 'tenant_profiles_org_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_profiles');
    }
};
