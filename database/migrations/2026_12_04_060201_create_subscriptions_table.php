<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An organization's GoProperty subscription (P06-01b; BILLING_PAYMENTS.md
 * § 2, DATABASE_SCHEMA.md § 2 `subscriptions`): one row per organization,
 * kept through cancellation and reactivation. Its terms are the plan's
 * version in effect on `current_period_start` (today while it has no
 * period, in trial). `organization_suspended`: the subscription suspended
 * or closed the organization, so becoming active again restores the
 * organization's status (a staff suspension is lifted by staff only).
 * Written only by App\Subscriptions\SubscriptionService. Payer, metering
 * and Paystack columns come with P06-02/P06-03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 10)->default('monthly');
            $table->string('status', 12);
            $table->timestamp('trial_ends_at')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('past_due_at')->nullable();
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('organization_suspended')->default(false);
            $table->timestamps();

            $table->index(['status', 'trial_ends_at']);
            $table->index(['plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
