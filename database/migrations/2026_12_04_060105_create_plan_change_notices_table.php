<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which organizations were told about a plan version before it takes
 * effect (P06-01a; BILLING_PAYMENTS.md § 1.3: 30 days' notice by email and
 * in-app banner). One row per version and organization, written before the
 * owners' emails are queued in the same transaction, so a retried or
 * replayed notice job tells each organization once. Never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->timestamps();

            $table->unique(['plan_version_id', 'organization_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_notices');
    }
};
