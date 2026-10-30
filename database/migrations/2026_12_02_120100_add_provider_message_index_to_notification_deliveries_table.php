<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WhatsApp's status webhooks (P05-06) find a delivery by Meta's message id
 * within the organization: an index for that lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->index(['organization_id', 'provider_message_id'], 'notification_deliveries_org_provider_message_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('notification_deliveries_org_provider_message_index');
        });
    }
};
