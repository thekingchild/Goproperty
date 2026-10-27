<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A lease's caution deposit as ledger entries (P03 decision 5,
 * DATABASE_SCHEMA.md § 3 `lease_deposit_entries`): taken at activation,
 * carried to a renewal, refunded or kept at move-out. Records only: no
 * money moves through GoProperty (AGENT.md § 3 rule 8). `amount_kobo` is
 * signed (a refund is negative), so the balance is the sum. Written only
 * by LeaseService; entries are never changed or deleted (history stays
 * with the organization that wrote it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_deposit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->restrictOnDelete();
            $table->string('type', 16);
            $table->bigInteger('amount_kobo');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'lease_id'], 'lease_deposit_entries_org_lease_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_deposit_entries');
    }
};
