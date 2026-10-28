<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The owner portal (P03-15, ARCHITECTURE.md § 4): an owner profile linked to
 * the user who signs in as that owner. Set only when the owner accepts an
 * owner invitation (Modules\Properties\OwnerPortal\OwnerInvitationAcceptance)
 * and cleared when the agency removes the owner's access. One profile per
 * user in an organization (unique; MySQL and MariaDB let many rows hold
 * null). The portal finds a user's owner profiles across organizations by
 * this column (the foreign key's index), one unscoped read of
 * `organization_id` and `id` (OwnerPortal\LinkedOwnerProfiles). A deleted
 * account leaves the profile unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->unique(['organization_id', 'user_id'], 'owner_profiles_organization_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->dropUnique('owner_profiles_organization_user_unique');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
