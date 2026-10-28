<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tickets that move with their building on a handover (P04-05;
 * DATABASE_SCHEMA.md § 5 `tickets`; BILLING_PAYMENTS.md § 7.4;
 * docs/plans/P04-subtasks.md "Settled by the product owner"). The
 * receiving organization's copy says where it came from
 * (`moved_from_*`: "Moved from {agency}, was TKT-000012", as the names
 * were at the move); the ticket left behind says where it went
 * (`moved_to_organization`, `moved_out_at`) and is closed, never reopened
 * there. `moved_from_ticket_id` points at the ticket left behind, in the
 * other organization: kept for the audit trail, never shown or followed
 * by a page. The TIMESTAMP is nullable (MariaDB 10.6, see
 * tests/Feature/Core/TimestampColumnsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('moved_from_ticket_id')->nullable()->after('reopen_count')->constrained('tickets')->restrictOnDelete();
            $table->string('moved_from_organization', 160)->nullable()->after('moved_from_ticket_id');
            $table->string('moved_from_number', 20)->nullable()->after('moved_from_organization');
            $table->string('moved_to_organization', 160)->nullable()->after('moved_from_number');
            $table->timestamp('moved_out_at')->nullable()->after('moved_to_organization');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_from_ticket_id');
            $table->dropColumn(['moved_from_organization', 'moved_from_number', 'moved_to_organization', 'moved_out_at']);
        });
    }
};
