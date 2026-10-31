<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The dunning ladder's notices (P06-05; BILLING_PAYMENTS.md § 6,
 * DATABASE_SCHEMA.md § 2 `dunning_notices`): one row for each notice the
 * ladder sends an organization's owners about its unpaid subscription,
 * written in the transaction that moves the subscription (or decides a
 * daily warning or a reminder is due), so the hourly job tells them once
 * however often it runs. Organization-scoped; written only by
 * Modules\SaasBilling\Dunning\DunningLadder; never changed or deleted.
 *
 * - `step`: `past_due`, `grace_warning` (the first day of grace and each
 *   day after), `suspended`, `suspension_reminder` (every 14 days
 *   suspended), `cancelled`.
 * - `notice_date`: the Lagos day it is for (a reminder: the day it fell
 *   due). Unique with the subscription and step.
 * - `next_step_on`: the day the next step comes if nothing is paid (the
 *   suspension, or the cancellation), as the notice said it.
 * - `overdue_kobo` and `invoice_numbers`: what was overdue then.
 * - `recipients`, `sms`: how many owners were told, and sent an SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('step', 30);
            $table->date('notice_date');
            $table->date('next_step_on')->nullable();
            $table->unsignedBigInteger('overdue_kobo');
            $table->json('invoice_numbers');
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->unsignedSmallInteger('sms')->default(0);
            $table->timestamps();

            $table->unique(['subscription_id', 'step', 'notice_date'], 'dunning_notices_step_unique');
            $table->index(['organization_id', 'notice_date'], 'dunning_notices_org_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_notices');
    }
};
