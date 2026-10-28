<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Owner portal invitations (kind `owner`, DATABASE_SCHEMA.md § 1
 * `invitations`, P03-15) point at the owner profile they are for. Added by
 * the Properties module with the table it refers to, as the Tenants module
 * adds `tenant_profile_id`. Owner profiles are never hard deleted
 * (archived), so the link restricts deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('owner_profile_id')->nullable()->after('tenant_profile_id')
                ->constrained('owner_profiles')->restrictOnDelete();
            $table->index(['organization_id', 'owner_profile_id', 'kind', 'status'], 'invitations_org_owner_kind_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex('invitations_org_owner_kind_status_index');
            $table->dropConstrainedForeignId('owner_profile_id');
        });
    }
};
