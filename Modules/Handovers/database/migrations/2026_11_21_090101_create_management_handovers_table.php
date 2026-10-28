<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Management handovers (DATABASE_SCHEMA.md § 1, BILLING_PAYMENTS.md § 7;
 * product owner decision 2026-10-27): a landlord's organization hands
 * chosen buildings to an agency for management, and gets them back when
 * either side ends it with notice. Replaces P02-04's
 * `billing_transfer_requests` (dropped by the SaasBilling module's
 * migration 2026_11_21_090106).
 *
 * The row belongs to the offering organization (`organization_id`); the
 * receiving one is `to_organization_id` and reads the row through
 * Modules\Handovers\Handovers\IncomingHandovers. `landlord_organization_id`
 * is where the buildings return at the end. Written only by
 * Modules\Handovers\Handovers\HandoverService (AGENT.md § 3 rule 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('landlord_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('rent_collected_by', 16)->default('agency');
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->string('plan_key', 32)->nullable();
            $table->unsignedInteger('billable_units')->nullable();
            $table->unsignedBigInteger('monthly_before_kobo')->nullable();
            $table->unsignedBigInteger('monthly_after_kobo')->nullable();
            $table->foreignId('owner_profile_id')->nullable()->constrained('owner_profiles')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('notice_given_by_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('notice_given_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notice_given_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // Named: the generated names pass MySQL's 64-character limit.
            $table->index(['organization_id', 'status'], 'mh_organization_status_index');
            $table->index(['to_organization_id', 'status'], 'mh_to_organization_status_index');
            $table->index(['status', 'expires_at'], 'mh_status_expires_index');
            $table->index(['status', 'ends_at'], 'mh_status_ends_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_handovers');
    }
};
