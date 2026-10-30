<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The organization's message templates as Meta last described them
 * (DATABASE_SCHEMA.md § 7 `whatsapp_templates`): written only by
 * Modules\WhatsApp\Templates\TemplateSync. A template gone from Meta is
 * kept as `disabled` (mappings and delivery history refer to it); rows are
 * never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_whatsapp_connection_id')->constrained('organization_whatsapp_connections')->restrictOnDelete();
            $table->string('meta_template_id', 40)->nullable();
            $table->string('name', 512);
            $table->string('language', 16);
            $table->string('category', 20);
            $table->string('status', 20);
            $table->string('meta_status', 40)->nullable();
            $table->json('components');
            $table->unsignedSmallInteger('variable_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name', 'language'], 'whatsapp_templates_org_name_language_unique');
            $table->index(['organization_id', 'status'], 'whatsapp_templates_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
