<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Invitations (DATABASE_SCHEMA.md § 1, ARCHITECTURE.md § 6.1,
 * ONBOARDING_COMPLIANCE.md § 2): a single-use link, valid 14 days, that
 * brings a person into an organization with roles. P01-04 builds member
 * invitations (kind `user`, channel `email`); the same table later carries
 * org-to-org billing-transfer invitations (P02-04: kind
 * `org_billing_transfer`, `target_organization_id`, plus its own
 * `billing_transfer_id` column) and tenant invitations by WhatsApp or a
 * shared link / QR code (P03-01b: channels `whatsapp` and `link`, `phone`).
 *
 * Only the SHA-256 of the token is stored; the link itself is in the email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32)->default('user');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->json('roles');
            $table->foreignId('target_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('channel', 16)->default('email');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'expires_at']);
            $table->index(['organization_id', 'email']);
            $table->index(['target_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
