<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tenant portal invitations and one-time sign-in links (kinds `tenant` and
 * `sign_in_link`, DATABASE_SCHEMA.md § 1 `invitations`, P03-01b) point at
 * the tenant they are for. Added by the Tenants module with the table it
 * refers to, as the SaasBilling module adds `billing_transfer_id`. Tenants
 * are never hard deleted (archived), so the link restricts deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('tenant_profile_id')->nullable()->after('user_id')
                ->constrained('tenant_profiles')->restrictOnDelete();
            $table->index(['organization_id', 'tenant_profile_id', 'kind', 'status'], 'invitations_org_tenant_kind_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex('invitations_org_tenant_kind_status_index');
            $table->dropConstrainedForeignId('tenant_profile_id');
        });
    }
};
