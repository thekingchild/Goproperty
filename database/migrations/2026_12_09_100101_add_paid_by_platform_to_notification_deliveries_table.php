<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P06-05: GoProperty's own SMS to an organization's owners (its
 * subscription is suspended, and the reminders every 14 days after) are
 * paid by GoProperty, never from the organization's SMS credits (the
 * product owner's decision of 2026-10-31). App\Sms\SendSms takes no credit
 * for a delivery marked so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->boolean('paid_by_platform')->default(false)->after('outcome_unknown');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('paid_by_platform');
        });
    }
};
