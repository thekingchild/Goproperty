<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per organization and event (DATABASE_SCHEMA.md § 7
 * `notification_channel_mappings`): the event's phone channels in order
 * (`["whatsapp","sms"]`; email goes wherever it goes anyway), the
 * WhatsApp template and which placeholder fills each of its variables.
 * `disabled_reason` says why a sync switched it off (the template was
 * paused, rejected, disabled or changed at Meta). Written only by
 * Modules\WhatsApp\Mappings\ChannelMappings and TemplateSync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 60);
            $table->json('channel_order');
            $table->foreignId('whatsapp_template_id')->nullable()->constrained('whatsapp_templates')->restrictOnDelete();
            $table->json('variable_map')->nullable();
            $table->boolean('active')->default(false);
            $table->string('disabled_reason', 40)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'event_key'], 'notification_channel_mappings_org_event_unique');
            $table->index(['organization_id', 'whatsapp_template_id'], 'notification_channel_mappings_org_template_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channel_mappings');
    }
};
