<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Addresses no email goes to (P05-04; PLATFORM_CONSOLE.md § 5 "Delivery"):
 * added by the provider's bounce and complaint webhooks or by staff, lifted
 * by staff once the owner has fixed their mailbox. One row per address
 * (lower-cased); a lifted address that bounces again is suppressed again on
 * the same row. Every change is audited (log `email`).
 *
 * Platform table: an address is suppressed for every organization at once,
 * since the provider refuses it for GoProperty's whole sending domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('reason', 20);
            $table->string('provider', 20)->nullable();
            $table->string('detail', 255)->nullable();
            $table->timestamp('suppressed_at')->useCurrent();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_staff_id')->nullable()->constrained('platform_staff')->restrictOnDelete();
            $table->timestamps();

            $table->index(['lifted_at', 'suppressed_at'], 'email_suppressions_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
