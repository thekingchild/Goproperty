<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the tenant was told about each payment (P03-10b; DATABASE_SCHEMA.md
 * § 4 `payment_notices`): one row per payment and transition (recorded,
 * confirmed, rejected, receipt voided), written by
 * Modules\Payments\Notices\TenantNotices before the notification is sent,
 * so a transition handled twice tells the tenant once (AGENT.md § 3 rule 6).
 * `channels` lists where it went (`database`, `mail`, `whatsapp`; never
 * SMS, P03 decision 3). History of the organization that recorded the
 * payment; never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('event', 40);
            $table->json('channels');
            $table->timestamps();

            $table->unique(['payment_id', 'event'], 'payment_notices_payment_event_unique');
            $table->index(['organization_id', 'payment_id'], 'payment_notices_org_payment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notices');
    }
};
