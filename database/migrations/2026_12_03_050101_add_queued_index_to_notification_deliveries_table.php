<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The console's delivery health dashboard (P05-05; PLATFORM_CONSOLE.md § 2
 * "Messaging") counts deliveries of every organization queued in the last
 * 24 hours and 7 days by channel and status, every 60 seconds: an index
 * that answers it without reading the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->index(['queued_at', 'channel', 'status'], 'notification_deliveries_queued_channel_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('notification_deliveries_queued_channel_index');
        });
    }
};
