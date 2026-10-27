<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Billing transfers between organizations (DATABASE_SCHEMA.md § 1,
 * BILLING_PAYMENTS.md § 7): one organization offers to hand its GoProperty
 * bill to another, which accepts or declines.
 *
 * The row belongs to the offering organization (`organization_id`, the
 * current payer: it offers, may cancel, and its bill is what moves); the
 * receiving one is `to_organization_id` and reads the row through
 * Modules\SaasBilling\Transfers\IncomingTransfers.
 *
 * Built ahead of the subscription tables (P02 decision 5):
 * `subscription_id` has no foreign key until P06-01 creates
 * `subscriptions`. `completed_at` stays empty until the payer actually
 * switches (P06-06); acceptance records `monthly_amount_kobo`, the plan
 * and the unit count the receiving owner saw.
 */
return new class extends Migration
{
    public function up(): void
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

            // Named: the generated names pass MySQL's 64-character limit.
            $table->index(['organization_id', 'status', 'expires_at'], 'btr_organization_status_expires_index');
            $table->index(['to_organization_id', 'status'], 'btr_to_organization_status_index');
            $table->index(['status', 'expires_at'], 'btr_status_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_transfer_requests');
    }
};
