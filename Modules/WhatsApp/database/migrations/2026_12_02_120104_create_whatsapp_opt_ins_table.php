<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tenants' consent to the agency's WhatsApp messages (DATABASE_SCHEMA.md
 * § 7 `whatsapp_opt_ins`; Meta policy): one row per opt-in, closed by
 * `opted_out_at` when they opt out (or opt in again at another number), so
 * the history stays. `tenant_profile_id` has no foreign key: the Tenants
 * module owns that table. Written only by
 * Modules\WhatsApp\OptIns\WhatsAppOptIns; never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_opt_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('tenant_profile_id');
            $table->string('phone', 20);
            $table->timestamp('opted_in_at')->useCurrent();
            $table->string('source', 24);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('opt_out_source', 24)->nullable();
            $table->foreignId('opted_out_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'tenant_profile_id', 'opted_out_at'], 'whatsapp_opt_ins_org_tenant_index');
            $table->index(['organization_id', 'phone'], 'whatsapp_opt_ins_org_phone_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_opt_ins');
    }
};
