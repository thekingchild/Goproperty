<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P05-03: whether any attempt to send the SMS ended without knowing if
 * GoText took it (GoText could not be reached, or the call timed out). Such
 * a message may have gone, so its SMS credit is never given back
 * (App\Sms\SendSms; docs/plans/P05-subtasks.md "Defaults taken in P05-03").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->boolean('outcome_unknown')->default(false)->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('outcome_unknown');
        });
    }
};
