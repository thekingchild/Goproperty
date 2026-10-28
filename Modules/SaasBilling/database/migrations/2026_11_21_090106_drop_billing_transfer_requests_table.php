<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `billing_transfer_requests` (P02-04) is replaced by `management_handovers`
 * (P03-14, DATABASE_SCHEMA.md § 1): an accepted billing transfer moved
 * nothing (no payer switch was ever built, P02 decision 5), so no row is
 * carried over; the audit log keeps each offer and answer (log
 * `billing_transfers`). down() makes the table again, empty, as P02-04
 * created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('billing_transfer_requests');
    }

    public function down(): void
    {
        Schema::create('billing_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('plan_key', 32)->nullable();
            $table->unsignedInteger('billable_units')->nullable();
            $table->unsignedBigInteger('monthly_amount_kobo')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'expires_at'], 'btr_organization_status_expires_index');
            $table->index(['to_organization_id', 'status'], 'btr_to_organization_status_index');
            $table->index(['status', 'expires_at'], 'btr_status_expires_index');
        });
    }
};
