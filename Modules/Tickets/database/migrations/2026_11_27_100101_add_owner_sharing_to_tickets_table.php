<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The agency chooses which tickets the building's owner sees (P04-06;
 * DATABASE_SCHEMA.md § 5 `tickets`; docs/plans/owner-visibility-2026-10-29.md
 * "Settled by the product owner" 4–5): `shared_with_owner_at` is when a
 * member shared the ticket with the owner of its building (null: hidden
 * from the owner) and `shared_with_owner_by_user_id` who did it (null on
 * delete: the member's account may go, the share stays). Both stay null
 * for every ticket that exists when this runs: every ticket starts hidden,
 * existing ones included. Written only by Modules\Tickets\Tickets\
 * TicketService. The index serves the owner portal's list (the shared
 * tickets of the owner's buildings). The TIMESTAMP is nullable (MariaDB
 * 10.6, see tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('shared_with_owner_at')->nullable()->after('moved_out_at');
            $table->foreignId('shared_with_owner_by_user_id')->nullable()->after('shared_with_owner_at')->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'property_id', 'shared_with_owner_at'], 'tickets_org_property_shared_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_org_property_shared_index');
            $table->dropConstrainedForeignId('shared_with_owner_by_user_id');
            $table->dropColumn('shared_with_owner_at');
        });
    }
};
